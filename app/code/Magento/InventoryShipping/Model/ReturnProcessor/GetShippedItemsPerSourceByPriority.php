<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\InventoryShipping\Model\ReturnProcessor;

use Magento\Framework\Exception\InputException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\InventoryApi\Api\GetSourcesAssignedToStockOrderedByPriorityInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\InventoryShipping\Model\ResourceModel\ShipmentSource\GetSourceCodeByShipmentId;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventorySalesApi\Model\ReturnProcessor\GetSourceDeductedOrderItemsInterface;
use Magento\InventorySalesApi\Model\ReturnProcessor\Result\SourceDeductedOrderItemFactory;
use Magento\InventorySalesApi\Model\ReturnProcessor\Result\SourceDeductedOrderItemsResultFactory;
use Magento\InventorySalesApi\Model\ReturnProcessor\Result\SourceDeductedOrderItemsResult;

class GetShippedItemsPerSourceByPriority implements GetSourceDeductedOrderItemsInterface
{
    /**
     * @var GetSourceCodeByShipmentId
     */
    private $getSourceCodeByShipmentId;

    /**
     * @var GetSkusByProductIdsInterface
     */
    private $getSkusByProductIds;

    /**
     * @var StockByWebsiteIdResolverInterface
     */
    private $stockByWebsiteIdResolver;

    /**
     * @var GetSourcesAssignedToStockOrderedByPriorityInterface
     */
    private $getSourcesAssignedToStockOrderedByPriority;

    /**
     * @var SourceDeductedOrderItemFactory
     */
    private $sourceDeductedOrderItemFactory;

    /**
     * @var SourceDeductedOrderItemsResultFactory
     */
    private $sourceDeductedOrderItemsResultFactory;

    /**
     * @param GetSourceCodeByShipmentId $getSourceCodeByShipmentId
     * @param GetSkusByProductIdsInterface $getSkusByProductIds
     * @param StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver
     * @param GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesAssignedToStockOrderedByPriority
     * @param SourceDeductedOrderItemFactory $sourceDeductedOrderItemFactory
     * @param SourceDeductedOrderItemsResultFactory $sourceDeductedOrderItemsResultFactory
     */
    public function __construct(
        GetSourceCodeByShipmentId $getSourceCodeByShipmentId,
        GetSkusByProductIdsInterface $getSkusByProductIds,
        StockByWebsiteIdResolverInterface $stockByWebsiteIdResolver,
        GetSourcesAssignedToStockOrderedByPriorityInterface $getSourcesAssignedToStockOrderedByPriority,
        SourceDeductedOrderItemFactory $sourceDeductedOrderItemFactory,
        SourceDeductedOrderItemsResultFactory $sourceDeductedOrderItemsResultFactory
    ) {
        $this->getSourceCodeByShipmentId = $getSourceCodeByShipmentId;
        $this->getSkusByProductIds = $getSkusByProductIds;
        $this->stockByWebsiteIdResolver = $stockByWebsiteIdResolver;
        $this->getSourcesAssignedToStockOrderedByPriority = $getSourcesAssignedToStockOrderedByPriority;
        $this->sourceDeductedOrderItemFactory = $sourceDeductedOrderItemFactory;
        $this->sourceDeductedOrderItemsResultFactory = $sourceDeductedOrderItemsResultFactory;
    }

    /**
     * @param OrderInterface $order
     * @param array $returnToStockItems
     * @return SourceDeductedOrderItemsResult[]
     * @throws InputException
     */
    public function execute(OrderInterface $order, array $returnToStockItems): array
    {
        $sources = [];
        /** @var Shipment $shipment */
        foreach ($order->getShipmentsCollection() as $shipment) {
            $sourceCode = $this->getSourceCodeByShipmentId->execute((int)$shipment->getId());
            foreach ($shipment->getItems() as $item) {
                if ($this->isValidItem($item->getOrderItem(), (float)$item->getQty(), $returnToStockItems)) {
                    $itemSku = $item->getSku() ?: $this->getSkusByProductIds->execute(
                        [$item->getProductId()]
                    )[$item->getProductId()];
                    $sources[$sourceCode][$itemSku] = ($sources[$sourceCode][$itemSku] ?? 0) + $item->getQty();
                }
            }
        }
        $websiteId = $order->getStore()->getWebsiteId();

        // Sort items by source priority
        $sources = $this->sortSourcesByPriority($sources, (int)$websiteId);

        // Group items by SKU
        $sources = $this->groupItemsBySku($sources);

        return $this->getSourceDeductedItemsResult($sources);
    }

    /**
     * @param array $shippedItems
     * @return SourceDeductedOrderItemsResult[]
     */
    private function getSourceDeductedItemsResult(array $shippedItems): array
    {
        $result = [];
        foreach ($shippedItems as $sourceCode => $items) {
            $deductedItems = [];
            foreach ($items as $sku => $qty) {
                $deductedItems[] = $this->sourceDeductedOrderItemFactory->create([
                    'sku' => $sku,
                    'quantity' => $qty
                ]);
            }
            $result[] = $this->sourceDeductedOrderItemsResultFactory->create([
                'sourceCode' => $sourceCode,
                'items' => $deductedItems
            ]);
        }

        return $result;
    }

    /**
     * Group items by SKU
     *
     * @param array $sources
     * @return array
     */
    private function groupItemsBySku(array $sources): array
    {
        $skuPerSource = $itemsGroupedBySku = [];
        foreach ($sources as $sourceCode => $data) {
            foreach ($data as $sku => $qty) {
                if (empty($skuPerSource[$sku])) {
                    $itemsGroupedBySku[$sourceCode][$sku] = $qty;
                    $skuPerSource[$sku] = $sourceCode;
                } else {
                    $existingSourceCode = $skuPerSource[$sku];
                    $itemsGroupedBySku[$existingSourceCode][$sku] += $qty;
                }
            }
        }

        return $itemsGroupedBySku;
    }

    /**
     * Sort items by source priority
     *
     * @param array $sources
     * @param int $websiteId
     * @return array
     */
    private function sortSourcesByPriority(array $sources, int $websiteId): array
    {
        $sourcesByPriority = [];
        try {
            $stockId = (int)$this->stockByWebsiteIdResolver->execute($websiteId)->getStockId();
            $assignedSourcesToStock = $this->getSourcesAssignedToStockOrderedByPriority->execute($stockId);
        } catch (LocalizedException $e) {
            $assignedSourcesToStock = [];
        }

        foreach ($assignedSourcesToStock as $source) {
            if (!empty($sources[$source->getSourceCode()])) {
                $sourcesByPriority[$source->getSourceCode()] = $sources[$source->getSourceCode()];
                unset($sources[$source->getSourceCode()]);
            }
        }

        foreach ($sources as $sourceCode => $data) {
            $sourcesByPriority[$sourceCode] = $data;
        }

        return $sourcesByPriority;
    }

    /**
     * @param OrderItemInterface $orderItem
     * @param float $qty
     * @param array $returnToStockItems
     * @return bool
     */
    private function isValidItem(OrderItemInterface $orderItem, float $qty, array $returnToStockItems): bool
    {
        $parentItemId = $orderItem->getParentItemId();

        return (in_array($orderItem->getId(), $returnToStockItems)
                || in_array($parentItemId, $returnToStockItems)) && $qty;
    }
}
