-- Run once before the new catalog import. Preserves all existing category values.
ALTER TABLE products MODIFY category ENUM('desk','display','automotive','other','home');
