-- =================================================================
-- 1. INSERT PROCUREMENTS (3 Drafts, 5 Open starting Sept 3, 2026)
-- =================================================================

-- Draft 1
INSERT INTO procurements (philgeps_ref_no, title, description, abc, procurement_mode, posting_date, closing_date, opening_date, status, created_by) 
VALUES ('PHILGEPS-2026-D01', 'Supply and Delivery of IT Equipment', 'Procurement of desktop computers and laptops for office use', 1500000.00, 'Public Bidding', '2026-09-03', '2026-09-17 10:00:00', '2026-09-17 14:00:00', 'draft', 1);
SET @p1 = LAST_INSERT_ID();

-- Draft 2
INSERT INTO procurements (philgeps_ref_no, title, description, abc, procurement_mode, posting_date, closing_date, opening_date, status, created_by) 
VALUES ('PHILGEPS-2026-D02', 'Procurement of Office Furniture', 'Supply of ergonomic chairs and modular workstations', 450000.00, 'Small Value Procurement', '2026-09-04', '2026-09-11 10:00:00', '2026-09-11 11:00:00', 'draft', 1);
SET @p2 = LAST_INSERT_ID();

-- Draft 3
INSERT INTO procurements (philgeps_ref_no, title, description, abc, procurement_mode, posting_date, closing_date, opening_date, status, created_by) 
VALUES ('PHILGEPS-2026-D03', 'Janitorial and Sanitation Services 2027', 'Annual contract for cleaning and sanitation maintenance', 2200000.00, 'Public Bidding', '2026-09-05', '2026-09-20 09:00:00', '2026-09-20 13:00:00', 'draft', 1);
SET @p3 = LAST_INSERT_ID();

-- Open 1
INSERT INTO procurements (philgeps_ref_no, title, description, abc, procurement_mode, posting_date, closing_date, opening_date, status, created_by) 
VALUES ('PHILGEPS-2026-O01', 'Rehabilitation of Main Office Building', 'Civil works for structural repairs and repainting', 5000000.00, 'Public Bidding', '2026-09-03', '2026-09-24 10:00:00', '2026-09-24 13:30:00', 'open', 1);
SET @p4 = LAST_INSERT_ID();

-- Open 2
INSERT INTO procurements (philgeps_ref_no, title, description, abc, procurement_mode, posting_date, closing_date, opening_date, status, created_by) 
VALUES ('PHILGEPS-2026-O02', 'Supply and Delivery of Office Consumables (Q4)', 'Procurement of paper, ink cartridges, and basic stationery', 350000.00, 'Shopping', '2026-09-04', '2026-09-10 12:00:00', '2026-09-10 14:00:00', 'open', 1);
SET @p5 = LAST_INSERT_ID();

-- Open 3
INSERT INTO procurements (philgeps_ref_no, title, description, abc, procurement_mode, posting_date, closing_date, opening_date, status, created_by) 
VALUES ('PHILGEPS-2026-O03', 'Preventive Maintenance of Air Conditioning Units', 'Quarterly check-up and servicing of HVAC systems', 280000.00, 'Small Value Procurement', '2026-09-05', '2026-09-12 10:00:00', '2026-09-12 11:00:00', 'open', 1);
SET @p6 = LAST_INSERT_ID();

-- Open 4
INSERT INTO procurements (philgeps_ref_no, title, description, abc, procurement_mode, posting_date, closing_date, opening_date, status, created_by) 
VALUES ('PHILGEPS-2026-O04', 'Upgrading of Network Infrastructure and Firewalls', 'Supply, installation, and deployment of enterprise network switches and firewalls', 3800000.00, 'Public Bidding', '2026-09-06', '2026-09-27 09:30:00', '2026-09-27 13:00:00', 'open', 1);
SET @p7 = LAST_INSERT_ID();

-- Open 5
INSERT INTO procurements (philgeps_ref_no, title, description, abc, procurement_mode, posting_date, closing_date, opening_date, status, created_by) 
VALUES ('PHILGEPS-2026-O05', 'Procurement of Security Guard Services', 'Provision of qualified security personnel for 12 months', 4100000.00, 'Public Bidding', '2026-09-07', '2026-09-28 10:00:00', '2026-09-28 14:00:00', 'open', 1);
SET @p8 = LAST_INSERT_ID();


-- =================================================================
-- 2. INSERT LOTS FOR EACH PROCUREMENT (Varied 3, 2, or 1 Lot)
-- =================================================================

INSERT INTO lots (procurement_id, lot_number, lot_title, description, abc) VALUES
-- Proc 1 (3 Lots)
(@p1, 1, 'Desktop Computers', 'High-performance workstation PCs', 900000.00),
(@p1, 2, 'Laptops and Ultrabooks', 'Portable computers for administrative staff', 450000.00),
(@p1, 3, 'Computer Accessories', 'Monitors, UPS, and peripherals', 150000.00),

-- Proc 2 (2 Lots)
(@p2, 1, 'Ergonomic Office Chairs', 'High-back mesh ergonomic chairs', 200000.00),
(@p2, 2, 'Modular Workstations', 'Cubicles and office tables', 250000.00),

-- Proc 3 (1 Lot)
(@p3, 1, 'Janitorial Services Contract', 'Complete cleaning services for 1 year', 2200000.00),

-- Proc 4 (3 Lots)
(@p4, 1, 'Civil Works & Masonry', 'Structural concrete repair and masonry', 2500000.00),
(@p4, 2, 'Roofing & Waterproofing', 'Roof replacement and leakproofing', 1500000.00),
(@p4, 3, 'Interior Painting', 'Wall repairs and eco-friendly painting', 1000000.00),

-- Proc 5 (2 Lots)
(@p5, 1, 'Paper & Printing Supplies', 'A4 and Legal copy papers', 200000.00),
(@p5, 2, 'Inks and Toners', 'Original ink cartridges and laser toners', 150000.00),

-- Proc 6 (1 Lot)
(@p6, 1, 'AC Preventive Maintenance', 'Complete servicing of split-type and window units', 280000.00),

-- Proc 7 (2 Lots)
(@p7, 1, 'Core and Edge Switches', 'Managed L2 and L3 network switches', 2200000.00),
(@p7, 2, 'Next-Gen Enterprise Firewalls', 'Hardware security appliances with licenses', 1600000.00),

-- Proc 8 (1 Lot)
(@p8, 1, 'Security Guard Personnel Contract', 'Posting of 10 licensed security guards', 4100000.00);