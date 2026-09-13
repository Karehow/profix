<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/schema.php';
migrate_replacement_evidence($conn);
echo "Replacement evidence migration completed.\n";
