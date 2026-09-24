-- ============================================================================
-- Sample_SMS_Test_Residents.sql
-- 50 sample residents for testing Disaster Alert SMS:
--   • 40 WITH a contact number, 10 WITHOUT
--   • Purok "SMS Test 1": 30 residents (20 with number, 10 without)
--   • Purok "SMS Test 2": 20 residents (all 20 with number)
--
-- Run in phpMyAdmin → barangay_db → SQL (MariaDB 10.2+ / XAMPP).
--
-- IMPORTANT: the 39 sample numbers (0900-000-0002 … 0900-000-0040) are FAKE.
-- Put YOUR OWN mobile number in @my_number so resident #1 receives the real
-- test SMS. Send the test alert with SMS audience = "By Area" → the two
-- "SMS Test" puroks, so real residents are NOT texted.
--
-- Remove everything again with the CLEANUP block at the bottom.
-- ============================================================================

SET @my_number = '';  -- e.g. '09171234567' (11 digits); leave '' to use a fake number

SET NAMES utf8mb4;

-- Barangay address / PSGC code from Manage Area → Default Barangay Address
-- (Announcements only lists areas of this barangay)
SET @psgc   = (SELECT COALESCE(psgc_barangay_code, '') FROM barangay_profile WHERE id = 1);
SET @psgc   = COALESCE(@psgc, '');

-- 1) The two test puroks in Resident Management → Manage Area
INSERT IGNORE INTO resident_areas (psgc_barangay_code, area_type, area_name, status) VALUES
  (@psgc, 'Purok', 'SMS Test 1', 'Active'),
  (@psgc, 'Purok', 'SMS Test 2', 'Active');

-- 2) 50 residents
INSERT INTO residents
  (ResidentCode, FirstName, MiddleName, LastName, Sex, BirthDate, CivilStatus, ContactNumber,
   HouseNumber, StreetName, Purok, AreaName, AreaType,
   BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode,
   PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode,
   IsHead, EmploymentStatus, Nationality, IsVoter, IsSenior, IsPWD, IsDeceased)
WITH RECURSIVE seq (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 50)
SELECT
  CONCAT('SMS-TEST-', LPAD(s.n, 3, '0')),
  ELT(1 + (s.n - 1) % 20, 'Juan','Maria','Pedro','Ana','Jose','Rosa','Carlo','Liza','Mark','Grace',
                          'Paolo','Joy','Ramon','Carmela','Dennis','Aileen','Rafael','Kristine','Noel','Jasmine'),
  ELT(1 + (s.n - 1) % 5,  'Santos','Reyes','Cruz','Bautista','Garcia'),
  CONCAT(ELT(1 + (s.n - 1) % 10, 'Dela Cruz','Santos','Garcia','Reyes','Mendoza','Torres','Flores','Ramos','Villanueva','Aquino'),
         ' (Test ', s.n, ')'),
  IF(s.n % 2 = 1, 'Male', 'Female'),
  DATE_SUB('2000-01-01', INTERVAL (s.n * 211) DAY),
  'Single',
  -- 1-20 → Purok SMS Test 1 WITH number, 21-30 → Purok SMS Test 1 NO number, 31-50 → Purok SMS Test 2 WITH number
  CASE
    WHEN s.n BETWEEN 21 AND 30 THEN NULL
    WHEN s.n = 1 AND @my_number <> '' THEN @my_number
    ELSE CONCAT('0900000', LPAD(s.n, 4, '0'))
  END,
  CONCAT(s.n),
  'Rizal St.',
  IF(s.n <= 30, 'SMS Test 1', 'SMS Test 2'),
  IF(s.n <= 30, 'SMS Test 1', 'SMS Test 2'),
  'Purok',
  bp.barangay_name, bp.municipality_name, bp.province_name, bp.region_name, bp.zip_code,
  bp.psgc_region_code, bp.psgc_province_code, bp.psgc_municipality_code, NULLIF(@psgc, ''),
  1, 'Employed', 'Filipino', 1,
  IF(s.n IN (5, 35), 1, 0),   -- 2 seniors (to test the "Vulnerable" audience)
  IF(s.n IN (8, 42), 1, 0),   -- 2 PWD
  0
FROM seq s
LEFT JOIN barangay_profile bp ON bp.id = 1;

-- 3) Check
SELECT AreaName AS purok,
       COUNT(*) AS residents,
       SUM(ContactNumber IS NOT NULL AND ContactNumber <> '') AS with_number,
       SUM(ContactNumber IS NULL OR ContactNumber = '') AS no_number
  FROM residents WHERE ResidentCode LIKE 'SMS-TEST-%'
 GROUP BY AreaName;

-- ============================================================================
-- CLEANUP — run these lines when you are done testing
-- ============================================================================
-- DELETE FROM sms_recipient_logs WHERE resident_id IN (SELECT ResidentID FROM residents WHERE ResidentCode LIKE 'SMS-TEST-%');
-- DELETE FROM residents WHERE ResidentCode LIKE 'SMS-TEST-%';
-- DELETE FROM resident_areas WHERE area_type = 'Purok' AND area_name IN ('SMS Test 1', 'SMS Test 2');
