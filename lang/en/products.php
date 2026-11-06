<?php

declare(strict_types=1);

return [
    'title' => 'Products',
    'subtitle' => 'Manage product catalog, variants, pricing, and master item classifications.',
    'add_product' => 'Add Product',
    'edit_product' => 'Edit Product',
    'bulk_edit' => 'Bulk Edit',
    'import_csv' => 'Import CSV',
    'export_excel' => 'Export Excel',
    'search_placeholder' => 'Search by name, SKU, or barcode...',
    'fields' => [
        'name' => 'Product Name',
        'code' => 'Product Code',
        'sku' => 'SKU',
        'barcode' => 'Barcode',
        'type' => 'Product Type',
        'category' => 'Category',
        'brand' => 'Brand',
        'unit' => 'Base Unit of Measure',
        'price' => 'Selling Price',
        'cost' => 'Cost of Goods Sold (COGS)',
        'stock' => 'Stock on Hand',
        'status' => 'Status',
        'track_inventory' => 'Track Inventory',
        'has_variants' => 'Has Variants',
    ],
    'types' => [
        'standard' => 'Standard Item',
        'service' => 'Service',
        'non_inventory' => 'Non-Inventory',
        'bundle' => 'Bundle / Kit',
    ],
    'empty' => [
        'title' => 'No Products Found',
        'description' => 'Create a new product or import products using CSV to get started.',
    ],
    'messages' => [
        'created' => 'Product created successfully.',
        'updated' => 'Product updated successfully.',
        'deleted' => 'Product deleted successfully.',
        'bulk_updated' => 'Selected products have been updated successfully.',
    ],
];
