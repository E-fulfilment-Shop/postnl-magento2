<?php
/**
 *
 *          ..::..
 *     ..::::::::::::..
 *   ::'''''':''::'''''::
 *   ::..  ..:  :  ....::
 *   ::::  :::  :  :   ::
 *   ::::  :::  :  ''' ::
 *   ::::..:::..::.....::
 *     ''::::::::::::''
 *          ''::''
 *
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to servicedesk@tig.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@tig.nl for more information.
 *
 * @copyright   Copyright (c) Total Internet Group B.V. https://tig.nl/copyright
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */

namespace TIG\PostNL\Service\Parcel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Quote\Model\ResourceModel\Quote\Item as QuoteItem;
use Magento\Sales\Api\Data\OrderItemInterface;
use TIG\PostNL\Config\Provider\ShippingOptions;
use TIG\PostNL\Config\Provider\LabelAndPackingslipOptions as LabelOptions;
use TIG\PostNL\Service\Options\ProductDictionary;
use TIG\PostNL\Service\Product\CollectionByAttributeValue;

abstract class CountAbstract extends CollectAbstract
{
    const CALCULATE_LABELS_WEIGHT       = 'weight';
    const CALCULATE_LABELS_PARCEL_COUNT = 'parcel_count';

    // @codingStandardsIgnoreLine
    protected $collectionByAttributeValue;

    // @codingStandardsIgnoreLine
    protected $shippingOptions;

    // @codingStandardsIgnoreLine
    protected $labelOptions;

    // @codingStandardsIgnoreLine
    protected $products;

    // @codingStandardsIgnoreLine
    protected $order;

    // @codingStandardsIgnoreLine
    protected $quantities;

    /**
     * CountAbstract constructor.
     *
     * @param \TIG\PostNL\Service\Options\ProductDictionary $productDictionary
     * @param \TIG\PostNL\Service\Product\CollectionByAttributeValue $collectionByAttributeValue
     * @param \TIG\PostNL\Config\Provider\ShippingOptions $shippingOptions
     * @param \TIG\PostNL\Config\Provider\LabelAndPackingslipOptions $labelOptions
     */
    public function __construct(
        ProductDictionary $productDictionary,
        CollectionByAttributeValue $collectionByAttributeValue,
        ShippingOptions $shippingOptions,
        LabelOptions $labelOptions
    ) {
        $this->productDictionary          = $productDictionary;
        $this->collectionByAttributeValue = $collectionByAttributeValue;
        $this->shippingOptions            = $shippingOptions;
        $this->labelOptions               = $labelOptions;
        parent::__construct(
            $productDictionary,
            $collectionByAttributeValue
        );
    }

    /**
     * @param $weight
     * @param $items
     *
     * @return int
     */
    // @codingStandardsIgnoreLine
    protected function calculate($weight, $items)
    {
        $this->products = $this->getProductsByType($items);
        $this->order = $items;
        /** If no PostNL Product Types are found, default to calculation by weight. */
        if (empty($this->products)) {
            return $this->getBasedOnWeight($weight);
        }

        foreach ($this->order as $orderItem) {
            /** @var $orderItem OrderItemInterface */
            $this->quantities[$orderItem->getProductId()] = $orderItem->getQtyOrdered();
        }

        $labelOption = $this->labelOptions->getCalculateLabels();
        if ($labelOption == self::CALCULATE_LABELS_PARCEL_COUNT) {
            return $this->calculateByParcelCount($weight, $items);
        }

        return $this->calculateByWeight($weight, $items);
    }

    /**
     * When 'parcel_count' is selected to calculate parcel count. Products without a
     * specified 'parcel_count' will still be calculated by weight.
     *
     * @param $items
     * @param $weight
     *
     * @return int
     */
    // @codingStandardsIgnoreLine
    protected function calculateByParcelCount($weight, $items)
    {
        $parcelCount = 0;

        $productsWithParcelCount = $this->getProductsWithParcelCount($items);
        if (!$productsWithParcelCount) {
            return $parcelCount;
        }

        foreach ($productsWithParcelCount as $item) {
            $parcelCount += $this->getBasedOnParcelCount($item);
        }

        $productsWithoutParcelCount = $this->getProductsWithoutParcelCount($items);
        if (!$productsWithoutParcelCount) {
            return $parcelCount;
        }

        $subtractWeight = 0;
        foreach ($productsWithoutParcelCount as $item) {
            $subtractWeight += $item->getWeight();
        }
        $parcelCount += $this->getBasedOnWeight($weight - $subtractWeight);

        return $parcelCount;
    }

    /**
     * When 'weight' is selected to calculate parcel count. The total parcel count for
     * Extra@Home products is calculated separately.
     *
     * @param $items
     * @param $weight
     *
     * @return int
     */
    // @codingStandardsIgnoreLine
    protected function calculateByWeight($items, $weight)
    {
        $parcelCount = $this->getBasedOnWeight($weight);

        $extraAtHomeProducts = $this->getExtraAtHomeProducts($items);
        if (!$extraAtHomeProducts) {
            return $parcelCount;
        }

        foreach ($extraAtHomeProducts as $item) {
            $parcelCount += $this->getBasedOnParcelCount($item);
        }

        return $parcelCount;
    }

    /**
     * When 'weight' is selected to calculate parcel count.
     *
     * @param $weight
     *
     * @return float|int
     */
    // @codingStandardsIgnoreLine
    protected function getBasedOnWeight($weight)
    {
        $maxWeight = $this->labelOptions->getCalculateLabelsMaxWeight() ?: 20000;
        $remainingParcelCount = ceil($weight / $maxWeight);
        $weightCount = $remainingParcelCount < 1 ? 1 : $remainingParcelCount;
        return $weightCount;
    }

    /**
     * @param ProductInterface $item
     *
     * @return float|int
     */
    // @codingStandardsIgnoreLine
    protected function getBasedOnParcelCount($item)
    {
        if (!isset($this->products[$item->getId()])) {
            return 0;
        }

        /** @var ProductInterface $product */
        $product = $this->products[$item->getId()];
        $productParcelCount = $product->getCustomAttribute(self::ATTRIBUTE_PARCEL_COUNT);

        /** If Parcel Count isn't set, it's value will be null. Which can't be multiplied. */
        if ($productParcelCount) {
            return ($productParcelCount->getValue() * $this->quantities[$item->getId()]);
        }

        return 0;
    }

    /**
     * @param $items
     *
     * @return int
     */
    // @codingStandardsIgnoreLine
    protected function getWeight($items)
    {
        $weight = 0;
        /** @var QuoteItem $item */
        foreach ($items as $item) {
            /** @noinspection PhpUndefinedMethodInspection */
            $weight += $item->getWeight();
        }

        return $weight;
    }
}
