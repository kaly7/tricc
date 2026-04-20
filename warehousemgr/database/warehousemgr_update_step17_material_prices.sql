-- warehousemgr step17: anyagtörzs ár mezők
ALTER TABLE material_items
    ADD COLUMN unit_price DECIMAL(14,2) NULL AFTER minimum_stock,
    ADD COLUMN currency_code VARCHAR(10) NULL AFTER unit_price;

ALTER TABLE material_items
    ADD KEY idx_material_unit_price (unit_price),
    ADD KEY idx_material_currency_code (currency_code);
