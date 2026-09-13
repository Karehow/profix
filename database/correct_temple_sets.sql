-- Correct the two existing demo items without changing stock or item IDs.
SET NAMES utf8mb4;
START TRANSACTION;
UPDATE items SET is_set = 1
WHERE image_url = '../uploads/temple_260907_10.jpg';
UPDATE items SET item_name = 'ชุดพานเงินและพานทอง', is_set = 1
WHERE image_url = '../uploads/temple_260907_40.jpg';
COMMIT;
