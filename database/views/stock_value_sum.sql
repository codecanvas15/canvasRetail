CREATE VIEW stock_value_sum AS 
SELECT
    i.item_code,
    pd.qty as procurement_qty,
    pd.total as procurement_total,
    null as sales_qty,
    null as sales_total,
    null as adjustment_qty,
    null as usage_qty,
    p.procurement_date as tx_date,
    p.created_at as created_at
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
    null as procurement_qty,
    null as procurement_total,
    sd.qty as sales_qty,
    sd.total as sales_total,
    null as adjustment_qty,
    null as usage_qty,
    s.sales_date as tx_date,
    s.created_at created_at
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
    null as procurement_qty,
    null as procurement_total,
    null as sales_qty,
    null as sales_total,
    sa.qty as adjustment_qty,
    null as usage_qty,
    sah.transaction_date as tx_date,
    sah.created_at as created_at
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
    null as procurement_qty,
    null as procurement_total,
    null as sales_qty,
    null as sales_total,
    null as adjustment_qty,
    su.qty as usage_qty,
    suh.transaction_date as tx_date,
    suh.created_at as created_at
FROM
    items i
    JOIN items_details id ON i.item_code = id.item_code and id.status = 1
    RIGHT JOIN stock_usage su ON id.id = su.item_detail_id and su.status = 1
    LEFT OUTER JOIN stock_usage_header suh ON su.stock_usage_id = suh.id and suh.status = 1
    JOIN locations l ON id.location_id = l.id and l.status = 1
WHERE
    i.status = 1;