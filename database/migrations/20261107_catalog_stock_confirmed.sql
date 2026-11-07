-- Owner-confirmed stock, 7 November 2026.
-- Optional follow-up ONLY if the catalog reset was already imported with stock 0.
-- Does not recreate products or change media, prices, copy, Walnut or Rainbow.
-- Runs once; future stock edits and sales are preserved on reimport.
SET NAMES utf8mb4;
START TRANSACTION;
SET @ggs_catalog_ready = (SELECT COUNT(*) FROM settings WHERE key_name = 'catalog_reset_20261107');
SET @ggs_stock_installed = (SELECT COUNT(*) FROM settings WHERE key_name = 'catalog_stock_confirmed_20261107');
SET @ggs_stock_product_count = (SELECT COUNT(*) FROM products WHERE slug IN (
  'magsafe-apple-watch-dock',
  'media-dock-organizer',
  'treble-clef-headphone-stand',
  'zippo-lighter-display-stand',
  'tealight-holder-set-black'
));
SET @ggs_update_stock = (@ggs_catalog_ready > 0 AND @ggs_stock_installed = 0 AND @ggs_stock_product_count = 5);
UPDATE products SET stock = 3
WHERE @ggs_update_stock = 1 AND slug IN (
  'magsafe-apple-watch-dock',
  'media-dock-organizer',
  'treble-clef-headphone-stand',
  'zippo-lighter-display-stand',
  'tealight-holder-set-black'
);
INSERT INTO settings (key_name, value)
SELECT 'catalog_stock_confirmed_20261107', 'installed' WHERE @ggs_update_stock = 1
ON DUPLICATE KEY UPDATE key_name = settings.key_name;
COMMIT;
SELECT CASE
  WHEN @ggs_stock_installed > 0 THEN 'Already installed - no stock changes made'
  WHEN @ggs_catalog_ready = 0 THEN 'NOT updated - import the catalog reset first'
  WHEN @ggs_stock_product_count <> 5 THEN 'NOT updated - expected five catalog products'
  ELSE 'Installed - stock 3 for the five confirmed products'
END AS stock_result;
