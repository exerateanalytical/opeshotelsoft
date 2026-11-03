<?php

// Edge node settings (docs/EDGE.md). Set as real environment variables on the edge server.
return [
    'mode' => (bool) env('EDGE_MODE', false),
    'hotel_id' => env('EDGE_HOTEL_ID') ? (int) env('EDGE_HOTEL_ID') : null,
    'node_id' => env('EDGE_NODE_ID') ? (int) env('EDGE_NODE_ID') : null,
    'secret' => env('EDGE_SECRET'),
    'cloud_url' => rtrim((string) env('EDGE_CLOUD_URL', ''), '/'),
    // Change capture on the cloud runs only for groups that have at least one edge node.
    'capture' => (bool) env('EDGE_CAPTURE', true),
    // Legal document numbers issued on the edge start above this value (cloud stays below).
    'number_range_start' => (int) env('EDGE_NUMBER_RANGE_START', 500000),
    // Primary keys created on the edge start at node_id * id_block.
    'id_block' => 1_000_000_000_000,
    'batch_size' => (int) env('EDGE_BATCH_SIZE', 200),
    'clock_skew' => 300, // seconds a signed request stays valid
];
