CREATE OR REPLACE VIEW stock_value AS
SELECT
    i.item_code,
    i.name as item_name,
    l.name as location_name,
    p.procurement_date,
    pd.qty as procurement_qty,
    pd.price as procurement_price,
    pd.total as procurement_total,
    null as sales_date,
    null as sales_qty,
    null as sales_price,
    null as sales_total,
    null as adjustment_date,
    null as adjustment_qty,
    null as usage_date,
    null as usage_qty,
    pd.created_at as created_at,
    p.doc_number as doc_number
FROM
    items i
    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
    RIGHT JOIN procurement_details pd ON id.id = pd.item_detail_id and pd.status = 1
    LEFT OUTER JOIN procurements p ON pd.procurement_id = p.id and p.status = 1
    JOIN locations l ON id.location_id = l.id and l.status = 1
WHERE
    i.status = 1
UNION ALL
SELECT
    i.item_code,
    i.name as item_name,
    l.name as location_name,
    null procurement_date,
    null as procurement_qty,
    null as procurement_price,
    null as procurement_total,
    s.sales_date,
    sd.qty as sales_qty,
    sd.price as sales_price,
    sd.total as sales_total,
    null as adjustment_date,
    null as adjustment_qty,
    null as usage_date,
    null as usage_qty,
    sd.created_at as created_at,
    s.doc_number as doc_number
FROM
    items i
    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
    RIGHT JOIN sales_details sd ON id.id = sd.item_detail_id and sd.status = 1
    LEFT OUTER JOIN sales s ON sd.sales_id = s.id and s.status = 1
    JOIN locations l ON id.location_id = l.id and l.status = 1
WHERE
    i.status = 1
UNION ALL
SELECT
    i.item_code,
    i.name as item_name,
    l.name as location_name,
    null as procurement_date,
    null as procurement_qty,
    null as procurement_price,
    null as procurement_total,
    null as sales_date,
    null as sales_qty,
    null as sales_price,
    null as sales_total,
    sah.transaction_date as adjustment_date,
    sa.qty as adjustment_qty,
    null as usage_date,
    null as usage_qty,
    sah.created_at as created_at,
    sah.doc_number as doc_number
FROM
    items i
    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
    RIGHT JOIN stock_adjustment sa ON id.id = sa.item_detail_id and sa.status = 1
    LEFT OUTER JOIN stock_adjustment_header sah ON sa.stock_adjustment_id = sah.id and sah.status = 1
    JOIN locations l ON id.location_id = l.id and l.status = 1
WHERE
    i.status = 1
UNION ALL
SELECT
    i.item_code,
    i.name as item_name,
    l.name as location_name,
    null as procurement_date,
    null as procurement_qty,
    null as procurement_price,
    null as procurement_total,
    null as sales_date,
    null as sales_qty,
    null as sales_price,
    null as sales_total,
    null as adjustment_date,
    null as adjustment_qty,
    suh.transaction_date as usage_date,
    su.qty as usage_qty,
    suh.created_at as created_at,
    suh.doc_number as doc_number
FROM
    items i
    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
    RIGHT JOIN stock_usage su ON id.id = su.item_detail_id and su.status = 1
    LEFT OUTER JOIN stock_usage_header suh ON su.stock_usage_id = suh.id and suh.status = 1
    JOIN locations l ON id.location_id = l.id and l.status = 1
WHERE
    i.status = 1;