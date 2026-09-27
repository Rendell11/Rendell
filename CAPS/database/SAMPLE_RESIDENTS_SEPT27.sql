-- ============================================================================
--  SAMPLE DATA — Barangay Biñang 2nd (run AFTER barangay_db_MERGED_SEPT27.sql)
--   • 30 residents in 8 households (heads + members, FamilyHeadID links)
--   • 5 residents have NO contact number (4 minors, 1 senior)
--   • 5 residents are current officials (Captain, 2 Kagawad, Secretary, SK)
--   • every household has a household_survey record (HH-2026-####) and is
--     pinned on the map (Latitude / Longitude on the head and members)
--   Emails use example.com (sample only). Coordinates are sample points around
--   Biñang 2nd, Bocaue, Bulacan — adjust on the map if needed.
--   Safe to import twice: it stops if the sample residents already exist.
-- ============================================================================
SET NAMES utf8mb4;
USE barangay_db;

-- Areas used by the sample addresses (Manage Area lists)
INSERT IGNORE INTO puroks (purok_name) VALUES ('Sampaguita'), ('Sunflower'), ('Carnation'), ('Dama de Noche');
INSERT IGNORE INTO resident_areas (psgc_barangay_code, area_type, area_name) VALUES
  ('0301404006', 'Purok', 'Sampaguita'),
  ('0301404006', 'Purok', 'Sunflower'),
  ('0301404006', 'Purok', 'Carnation'),
  ('0301404006', 'Purok', 'Dama de Noche');
INSERT IGNORE INTO resident_streets (psgc_barangay_code, street_name) VALUES
  ('0301404006', 'Rizal St.'),
  ('0301404006', 'Mabini St.'),
  ('0301404006', 'Bonifacio St.'),
  ('0301404006', 'Luna St.'),
  ('0301404006', 'Del Pilar St.');

DROP PROCEDURE IF EXISTS seed_sample_residents;
DELIMITER $$
CREATE PROCEDURE seed_sample_residents()
seed: BEGIN
  DECLARE h INT UNSIGNED;
  DECLARE sid INT UNSIGNED;
  DECLARE rs INT UNSIGNED DEFAULT 0;
  DECLARE hh INT UNSIGNED DEFAULT 0;
  IF EXISTS (SELECT 1 FROM residents WHERE Email = 'juan.delacruz@example.com') THEN
    SELECT 'Sample residents already exist - nothing added.' AS result;
    LEAVE seed;
  END IF;

  -- next RES-2026-#### and HH-2026-#### numbers (continue after existing ones)
  SET rs = (SELECT COALESCE(MAX(CAST(SUBSTRING(ResidentCode, 10) AS UNSIGNED)), 0)
             FROM residents WHERE ResidentCode LIKE 'RES-2026-%');
  SET hh = (SELECT COALESCE(MAX(CAST(SUBSTRING(HouseholdID, 9) AS UNSIGNED)), 0)
             FROM household_survey WHERE HouseholdID LIKE 'HH-2026-%');


  -- Household 1: Dela Cruz family, Purok Sampaguita (5 people)
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Juan', 'Santos', 'Dela Cruz', NULL, 'Male', '1974-03-15', 'Bocaue, Bulacan', 'Married', '09171230001', 'juan.delacruz@example.com', '12', 'Rizal St.', 'Sampaguita', 'Sampaguita', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8036120, 120.9338410, 1, 'Head of Family', NULL, 'Employed', 'Barangay Captain', 53000.00, 'College Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET h = LAST_INSERT_ID();
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Maria', 'Reyes', 'Dela Cruz', NULL, 'Female', '1977-08-02', 'Bocaue, Bulacan', 'Married', '09171230002', 'maria.delacruz@example.com', '12', 'Rizal St.', 'Sampaguita', 'Sampaguita', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8036120, 120.9338410, 0, 'Spouse', h, 'Self-Employed', 'Sari-sari Store Owner', 15000.00, 'High School Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Paolo', 'Reyes', 'Dela Cruz', NULL, 'Male', '2004-01-20', 'Bocaue, Bulacan', 'Single', '09171230003', 'paolo.delacruz@example.com', '12', 'Rizal St.', 'Sampaguita', 'Sampaguita', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8036120, 120.9338410, 0, 'Son', h, 'Student', NULL, 0.00, 'College Level', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Andrea', 'Reyes', 'Dela Cruz', NULL, 'Female', '2010-05-11', 'Bocaue, Bulacan', 'Single', NULL, NULL, '12', 'Rizal St.', 'Sampaguita', 'Sampaguita', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8036120, 120.9338410, 0, 'Daughter', h, 'Student', NULL, 0.00, 'High School Level', 0, NULL, 0, 0, 0, 'Roman Catholic', 'Filipino', 0);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Miguel', 'Reyes', 'Dela Cruz', NULL, 'Male', '2016-09-30', 'Bocaue, Bulacan', 'Single', NULL, NULL, '12', 'Rizal St.', 'Sampaguita', 'Sampaguita', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8036120, 120.9338410, 0, 'Son', h, 'Student', NULL, 0.00, 'Elementary Level', 0, NULL, 0, 0, 0, 'Roman Catholic', 'Filipino', 0);
  SET hh = hh + 1;
  INSERT INTO household_survey (HouseholdID, ResidentID, head_name, address, civil_status, sex,
      contact_number, income_bracket, housing_tenure, has_electricity, has_internet, water_source,
      household_name, date_of_interview)
  VALUES (CONCAT('HH-2026-', LPAD(hh, 4, '0')), h, 'Juan Santos Dela Cruz', '12 Rizal St., Purok Sampaguita, Brgy. Biñang 2nd, Bocaue, Bulacan', 'Married', 'Male',
      '09171230001', 'Middle Income', 'Owned', 1, 1, 'Water District',
      'Dela Cruz Family', '2026-09-01');
  SET sid = LAST_INSERT_ID();
  INSERT INTO household_survey_members (SurveyID, ResidentID, MemberNumber, full_name, sex, age,
      relationship, civil_status, education, monthly_income)
  SELECT sid, r.ResidentID, ROW_NUMBER() OVER (ORDER BY r.IsHead DESC, r.ResidentID), 
         TRIM(CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix)), r.Sex,
         TIMESTAMPDIFF(YEAR, r.BirthDate, CURDATE()), r.RelationshipToHead, r.CivilStatus,
         r.EducationLevel, r.TotalHouseholdIncome
  FROM residents r WHERE r.ResidentID = h OR r.FamilyHeadID = h;

  -- Household 2: Santos family, Purok Sunflower (4 people)
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Roberto', 'Garcia', 'Santos', NULL, 'Male', '1979-11-08', 'Bocaue, Bulacan', 'Married', '09181230004', 'roberto.santos@example.com', '45', 'Mabini St.', 'Sunflower', 'Sunflower', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8049730, 120.9351260, 1, 'Head of Family', NULL, 'Employed', 'Jeepney Operator', 32000.00, 'High School Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET h = LAST_INSERT_ID();
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Liza', 'Ramos', 'Santos', NULL, 'Female', '1981-02-14', 'Bocaue, Bulacan', 'Married', '09181230005', 'liza.santos@example.com', '45', 'Mabini St.', 'Sunflower', 'Sunflower', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8049730, 120.9351260, 0, 'Spouse', h, 'Homemaker', NULL, 0.00, 'High School Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Kristine', 'Ramos', 'Santos', NULL, 'Female', '2007-06-25', 'Bocaue, Bulacan', 'Single', '09181230006', 'kristine.santos@example.com', '45', 'Mabini St.', 'Sunflower', 'Sunflower', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8049730, 120.9351260, 0, 'Daughter', h, 'Student', NULL, 0.00, 'College Level', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Mark Anthony', 'Ramos', 'Santos', NULL, 'Male', '2012-04-03', 'Bocaue, Bulacan', 'Single', '09181230007', NULL, '45', 'Mabini St.', 'Sunflower', 'Sunflower', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8049730, 120.9351260, 0, 'Son', h, 'Student', NULL, 0.00, 'High School Level', 0, NULL, 0, 0, 0, 'Roman Catholic', 'Filipino', 0);
  SET hh = hh + 1;
  INSERT INTO household_survey (HouseholdID, ResidentID, head_name, address, civil_status, sex,
      contact_number, income_bracket, housing_tenure, has_electricity, has_internet, water_source,
      household_name, date_of_interview)
  VALUES (CONCAT('HH-2026-', LPAD(hh, 4, '0')), h, 'Roberto Garcia Santos', '45 Mabini St., Purok Sunflower, Brgy. Biñang 2nd, Bocaue, Bulacan', 'Married', 'Male',
      '09181230004', 'Lower Middle Income', 'Owned', 1, 1, 'Water District',
      'Santos Family', '2026-09-01');
  SET sid = LAST_INSERT_ID();
  INSERT INTO household_survey_members (SurveyID, ResidentID, MemberNumber, full_name, sex, age,
      relationship, civil_status, education, monthly_income)
  SELECT sid, r.ResidentID, ROW_NUMBER() OVER (ORDER BY r.IsHead DESC, r.ResidentID), 
         TRIM(CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix)), r.Sex,
         TIMESTAMPDIFF(YEAR, r.BirthDate, CURDATE()), r.RelationshipToHead, r.CivilStatus,
         r.EducationLevel, r.TotalHouseholdIncome
  FROM residents r WHERE r.ResidentID = h OR r.FamilyHeadID = h;

  -- Household 3: Reyes family, Purok Carnation (4 people)
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Elena', 'Cruz', 'Reyes', NULL, 'Female', '1988-07-19', 'Bocaue, Bulacan', 'Widowed', '09191230008', 'elena.reyes@example.com', '8', 'Bonifacio St.', 'Carnation', 'Carnation', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8021480, 120.9324870, 1, 'Head of Family', NULL, 'Employed', 'Barangay Secretary', 28000.00, 'College Graduate', 0, NULL, 0, 1, 1, 'Roman Catholic', 'Filipino', 1);
  SET h = LAST_INSERT_ID();
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Joshua', 'Cruz', 'Reyes', NULL, 'Male', '2009-10-12', 'Bocaue, Bulacan', 'Single', '09191230009', NULL, '8', 'Bonifacio St.', 'Carnation', 'Carnation', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8021480, 120.9324870, 0, 'Son', h, 'Student', NULL, 0.00, 'High School Level', 0, NULL, 0, 0, 0, 'Roman Catholic', 'Filipino', 0);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Bea', 'Cruz', 'Reyes', NULL, 'Female', '2013-03-27', 'Bocaue, Bulacan', 'Single', '09191230010', NULL, '8', 'Bonifacio St.', 'Carnation', 'Carnation', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8021480, 120.9324870, 0, 'Daughter', h, 'Student', NULL, 0.00, 'Elementary Level', 0, NULL, 0, 0, 0, 'Roman Catholic', 'Filipino', 0);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Lourdes', 'Mercado', 'Cruz', NULL, 'Female', '1958-12-05', 'Bocaue, Bulacan', 'Widowed', '09191230011', 'lourdes.cruz@example.com', '8', 'Bonifacio St.', 'Carnation', 'Carnation', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8021480, 120.9324870, 0, 'Mother', h, 'Retired', NULL, 6000.00, 'Elementary Graduate', 0, NULL, 1, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET hh = hh + 1;
  INSERT INTO household_survey (HouseholdID, ResidentID, head_name, address, civil_status, sex,
      contact_number, income_bracket, housing_tenure, has_electricity, has_internet, water_source,
      household_name, date_of_interview)
  VALUES (CONCAT('HH-2026-', LPAD(hh, 4, '0')), h, 'Elena Cruz Reyes', '8 Bonifacio St., Purok Carnation, Brgy. Biñang 2nd, Bocaue, Bulacan', 'Widowed', 'Female',
      '09191230008', 'Lower Middle Income', 'Rented', 1, 1, 'Water District',
      'Reyes Family', '2026-09-01');
  SET sid = LAST_INSERT_ID();
  INSERT INTO household_survey_members (SurveyID, ResidentID, MemberNumber, full_name, sex, age,
      relationship, civil_status, education, monthly_income)
  SELECT sid, r.ResidentID, ROW_NUMBER() OVER (ORDER BY r.IsHead DESC, r.ResidentID), 
         TRIM(CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix)), r.Sex,
         TIMESTAMPDIFF(YEAR, r.BirthDate, CURDATE()), r.RelationshipToHead, r.CivilStatus,
         r.EducationLevel, r.TotalHouseholdIncome
  FROM residents r WHERE r.ResidentID = h OR r.FamilyHeadID = h;

  -- Household 4: Mendoza family, Purok Dama de Noche (4 people)
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Antonio', 'Villareal', 'Mendoza', NULL, 'Male', '1965-01-22', 'Bocaue, Bulacan', 'Married', '09201230012', 'antonio.mendoza@example.com', '21', 'Luna St.', 'Dama de Noche', 'Dama de Noche', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8058840, 120.9329550, 1, 'Head of Family', NULL, 'Employed', 'Barangay Kagawad', 107000.00, 'College Graduate', 0, NULL, 1, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET h = LAST_INSERT_ID();
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Rosario', 'Lim', 'Mendoza', NULL, 'Female', '1967-09-09', 'Bocaue, Bulacan', 'Married', '09201230013', 'rosario.mendoza@example.com', '21', 'Luna St.', 'Dama de Noche', 'Dama de Noche', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8058840, 120.9329550, 0, 'Spouse', h, 'Retired', NULL, 8000.00, 'College Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Carlo', 'Lim', 'Mendoza', NULL, 'Male', '1996-05-17', 'Bocaue, Bulacan', 'Married', '09201230014', 'carlo.mendoza@example.com', '21', 'Luna St.', 'Dama de Noche', 'Dama de Noche', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8058840, 120.9329550, 0, 'Son', h, 'Employed', 'Nurse', 28000.00, 'College Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Jenny', 'Pascual', 'Mendoza', NULL, 'Female', '1998-11-30', 'Bocaue, Bulacan', 'Married', '09201230015', 'jenny.mendoza@example.com', '21', 'Luna St.', 'Dama de Noche', 'Dama de Noche', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8058840, 120.9329550, 0, 'Daughter-in-law', h, 'Employed', 'Teacher', 26000.00, 'College Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET hh = hh + 1;
  INSERT INTO household_survey (HouseholdID, ResidentID, head_name, address, civil_status, sex,
      contact_number, income_bracket, housing_tenure, has_electricity, has_internet, water_source,
      household_name, date_of_interview)
  VALUES (CONCAT('HH-2026-', LPAD(hh, 4, '0')), h, 'Antonio Villareal Mendoza', '21 Luna St., Purok Dama de Noche, Brgy. Biñang 2nd, Bocaue, Bulacan', 'Married', 'Male',
      '09201230012', 'Upper Middle Income', 'Owned', 1, 1, 'Water District',
      'Mendoza Family', '2026-09-01');
  SET sid = LAST_INSERT_ID();
  INSERT INTO household_survey_members (SurveyID, ResidentID, MemberNumber, full_name, sex, age,
      relationship, civil_status, education, monthly_income)
  SELECT sid, r.ResidentID, ROW_NUMBER() OVER (ORDER BY r.IsHead DESC, r.ResidentID), 
         TRIM(CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix)), r.Sex,
         TIMESTAMPDIFF(YEAR, r.BirthDate, CURDATE()), r.RelationshipToHead, r.CivilStatus,
         r.EducationLevel, r.TotalHouseholdIncome
  FROM residents r WHERE r.ResidentID = h OR r.FamilyHeadID = h;

  -- Household 5: Bautista family, Purok Sampaguita (4 people)
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Ramon', 'Dizon', 'Bautista', NULL, 'Male', '1991-04-08', 'Bocaue, Bulacan', 'Married', '09211230016', 'ramon.bautista@example.com', '3', 'Del Pilar St.', 'Sampaguita', 'Sampaguita', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8029350, 120.9357820, 1, 'Head of Family', NULL, 'Employed', 'Factory Worker', 27000.00, 'Vocational', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET h = LAST_INSERT_ID();
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Grace', 'Tolentino', 'Bautista', NULL, 'Female', '1993-08-21', 'Bocaue, Bulacan', 'Married', '09211230017', 'grace.bautista@example.com', '3', 'Del Pilar St.', 'Sampaguita', 'Sampaguita', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8029350, 120.9357820, 0, 'Spouse', h, 'Self-Employed', 'Online Seller', 9000.00, 'College Level', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Liam', 'Tolentino', 'Bautista', NULL, 'Male', '2019-02-15', 'Bocaue, Bulacan', 'Single', NULL, NULL, '3', 'Del Pilar St.', 'Sampaguita', 'Sampaguita', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8029350, 120.9357820, 0, 'Son', h, 'Student', NULL, 0.00, 'Elementary Level', 0, NULL, 0, 0, 0, 'Roman Catholic', 'Filipino', 0);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Sofia', 'Tolentino', 'Bautista', NULL, 'Female', '2022-07-04', 'Bocaue, Bulacan', 'Single', NULL, NULL, '3', 'Del Pilar St.', 'Sampaguita', 'Sampaguita', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8029350, 120.9357820, 0, 'Daughter', h, 'Unemployed', NULL, 0.00, 'None', 0, NULL, 0, 0, 0, 'Roman Catholic', 'Filipino', 0);
  SET hh = hh + 1;
  INSERT INTO household_survey (HouseholdID, ResidentID, head_name, address, civil_status, sex,
      contact_number, income_bracket, housing_tenure, has_electricity, has_internet, water_source,
      household_name, date_of_interview)
  VALUES (CONCAT('HH-2026-', LPAD(hh, 4, '0')), h, 'Ramon Dizon Bautista', '3 Del Pilar St., Purok Sampaguita, Brgy. Biñang 2nd, Bocaue, Bulacan', 'Married', 'Male',
      '09211230016', 'Lower Middle Income', 'Owned', 1, 1, 'Water District',
      'Bautista Family', '2026-09-01');
  SET sid = LAST_INSERT_ID();
  INSERT INTO household_survey_members (SurveyID, ResidentID, MemberNumber, full_name, sex, age,
      relationship, civil_status, education, monthly_income)
  SELECT sid, r.ResidentID, ROW_NUMBER() OVER (ORDER BY r.IsHead DESC, r.ResidentID), 
         TRIM(CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix)), r.Sex,
         TIMESTAMPDIFF(YEAR, r.BirthDate, CURDATE()), r.RelationshipToHead, r.CivilStatus,
         r.EducationLevel, r.TotalHouseholdIncome
  FROM residents r WHERE r.ResidentID = h OR r.FamilyHeadID = h;

  -- Household 6: Villanueva family, Purok Sunflower (3 people)
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Teresita', 'Manalo', 'Villanueva', NULL, 'Female', '1954-06-13', 'Bocaue, Bulacan', 'Widowed', NULL, NULL, '17', 'Rizal St.', 'Sunflower', 'Sunflower', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8043260, 120.9316940, 1, 'Head of Family', NULL, 'Retired', NULL, 34000.00, 'Elementary Graduate', 1, 'Orthopedic Disability', 1, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET h = LAST_INSERT_ID();
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Kevin', 'Perez', 'Villanueva', NULL, 'Male', '2001-09-01', 'Bocaue, Bulacan', 'Single', '09221230018', 'kevin.villanueva@example.com', '17', 'Rizal St.', 'Sunflower', 'Sunflower', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8043260, 120.9316940, 0, 'Grandson', h, 'Employed', 'Call Center Agent', 25000.00, 'College Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Janine', 'Perez', 'Villanueva', NULL, 'Female', '2005-03-22', 'Bocaue, Bulacan', 'Single', '09221230019', 'janine.villanueva@example.com', '17', 'Rizal St.', 'Sunflower', 'Sunflower', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8043260, 120.9316940, 0, 'Granddaughter', h, 'Student', NULL, 0.00, 'College Level', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET hh = hh + 1;
  INSERT INTO household_survey (HouseholdID, ResidentID, head_name, address, civil_status, sex,
      contact_number, income_bracket, housing_tenure, has_electricity, has_internet, water_source,
      household_name, date_of_interview)
  VALUES (CONCAT('HH-2026-', LPAD(hh, 4, '0')), h, 'Teresita Manalo Villanueva', '17 Rizal St., Purok Sunflower, Brgy. Biñang 2nd, Bocaue, Bulacan', 'Widowed', 'Female',
      '', 'Lower Middle Income', 'Owned', 1, 1, 'Water District',
      'Villanueva Family', '2026-09-01');
  SET sid = LAST_INSERT_ID();
  INSERT INTO household_survey_members (SurveyID, ResidentID, MemberNumber, full_name, sex, age,
      relationship, civil_status, education, monthly_income)
  SELECT sid, r.ResidentID, ROW_NUMBER() OVER (ORDER BY r.IsHead DESC, r.ResidentID), 
         TRIM(CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix)), r.Sex,
         TIMESTAMPDIFF(YEAR, r.BirthDate, CURDATE()), r.RelationshipToHead, r.CivilStatus,
         r.EducationLevel, r.TotalHouseholdIncome
  FROM residents r WHERE r.ResidentID = h OR r.FamilyHeadID = h;

  -- Household 7: Aquino family, Purok Carnation (3 people)
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Dennis', 'Flores', 'Aquino', NULL, 'Male', '1997-12-10', 'Bocaue, Bulacan', 'Single', '09231230020', 'dennis.aquino@example.com', '30', 'Mabini St.', 'Carnation', 'Carnation', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8014720, 120.9345180, 1, 'Head of Family', NULL, 'Employed', 'Tricycle Driver', 27000.00, 'High School Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET h = LAST_INSERT_ID();
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Mark', 'Flores', 'Aquino', NULL, 'Male', '2000-02-28', 'Bocaue, Bulacan', 'Single', '09231230021', 'mark.aquino@example.com', '30', 'Mabini St.', 'Carnation', 'Carnation', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8014720, 120.9345180, 0, 'Brother', h, 'Employed', 'Construction Worker', 13000.00, 'High School Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Ana Marie', 'Flores', 'Aquino', NULL, 'Female', '2003-10-05', 'Bocaue, Bulacan', 'Single', '09231230022', 'ana.aquino@example.com', '30', 'Mabini St.', 'Carnation', 'Carnation', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8014720, 120.9345180, 0, 'Sister', h, 'Student', NULL, 0.00, 'College Level', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET hh = hh + 1;
  INSERT INTO household_survey (HouseholdID, ResidentID, head_name, address, civil_status, sex,
      contact_number, income_bracket, housing_tenure, has_electricity, has_internet, water_source,
      household_name, date_of_interview)
  VALUES (CONCAT('HH-2026-', LPAD(hh, 4, '0')), h, 'Dennis Flores Aquino', '30 Mabini St., Purok Carnation, Brgy. Biñang 2nd, Bocaue, Bulacan', 'Single', 'Male',
      '09231230020', 'Lower Middle Income', 'Rented', 1, 1, 'Water District',
      'Aquino Family', '2026-09-01');
  SET sid = LAST_INSERT_ID();
  INSERT INTO household_survey_members (SurveyID, ResidentID, MemberNumber, full_name, sex, age,
      relationship, civil_status, education, monthly_income)
  SELECT sid, r.ResidentID, ROW_NUMBER() OVER (ORDER BY r.IsHead DESC, r.ResidentID), 
         TRIM(CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix)), r.Sex,
         TIMESTAMPDIFF(YEAR, r.BirthDate, CURDATE()), r.RelationshipToHead, r.CivilStatus,
         r.EducationLevel, r.TotalHouseholdIncome
  FROM residents r WHERE r.ResidentID = h OR r.FamilyHeadID = h;

  -- Household 8: Ramos family, Purok Dama de Noche (3 people)
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Jose', 'Navarro', 'Ramos', 'Jr.', 'Male', '1982-05-30', 'Bocaue, Bulacan', 'Married', '09241230023', 'jose.ramos@example.com', '5', 'Bonifacio St.', 'Dama de Noche', 'Dama de Noche', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8064510, 120.9342730, 1, 'Head of Family', NULL, 'Self-Employed', 'Welder', 32000.00, 'Vocational', 1, 'Orthopedic Disability', 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET h = LAST_INSERT_ID();
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Cecilia', 'Gomez', 'Ramos', NULL, 'Female', '1984-01-17', 'Bocaue, Bulacan', 'Married', '09241230024', 'cecilia.ramos@example.com', '5', 'Bonifacio St.', 'Dama de Noche', 'Dama de Noche', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8064510, 120.9342730, 0, 'Spouse', h, 'Employed', 'Market Vendor', 12000.00, 'High School Graduate', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET rs = rs + 1;
  INSERT INTO residents (ResidentCode, FirstName, MiddleName, LastName, Suffix, Sex, BirthDate, BirthPlace, CivilStatus, ContactNumber, Email, HouseNumber, StreetName, Purok, AreaName, AreaType, BarangayName, CityMunicipalityName, ProvinceName, RegionName, ZipCode, PSGCRegionCode, PSGCProvinceCode, PSGCMunicipalityCode, PSGCBarangayCode, Latitude, Longitude, IsHead, RelationshipToHead, FamilyHeadID, EmploymentStatus, Occupation, TotalHouseholdIncome, EducationLevel, IsPWD, PWDClassification, IsSenior, IsSoloParent, IsVoter, Religion, Nationality, HasPhilhealth)
  VALUES (CONCAT('RES-2026-', LPAD(rs, 4, '0')), 'Nicole', 'Gomez', 'Ramos', NULL, 'Female', '2008-08-08', 'Bocaue, Bulacan', 'Single', '09241230025', 'nicole.ramos@example.com', '5', 'Bonifacio St.', 'Dama de Noche', 'Dama de Noche', 'Purok', 'Biñang 2nd', 'Bocaue', 'Bulacan', 'Region III (Central Luzon)', '3018', '0300000000', '0301400000', '0301404000', '0301404006', 14.8064510, 120.9342730, 0, 'Daughter', h, 'Student', NULL, 0.00, 'College Level', 0, NULL, 0, 0, 1, 'Roman Catholic', 'Filipino', 1);
  SET hh = hh + 1;
  INSERT INTO household_survey (HouseholdID, ResidentID, head_name, address, civil_status, sex,
      contact_number, income_bracket, housing_tenure, has_electricity, has_internet, water_source,
      household_name, date_of_interview)
  VALUES (CONCAT('HH-2026-', LPAD(hh, 4, '0')), h, 'Jose Navarro Ramos Jr.', '5 Bonifacio St., Purok Dama de Noche, Brgy. Biñang 2nd, Bocaue, Bulacan', 'Married', 'Male',
      '09241230023', 'Lower Middle Income', 'Owned', 1, 1, 'Water District',
      'Ramos Family', '2026-09-01');
  SET sid = LAST_INSERT_ID();
  INSERT INTO household_survey_members (SurveyID, ResidentID, MemberNumber, full_name, sex, age,
      relationship, civil_status, education, monthly_income)
  SELECT sid, r.ResidentID, ROW_NUMBER() OVER (ORDER BY r.IsHead DESC, r.ResidentID), 
         TRIM(CONCAT_WS(' ', r.FirstName, r.MiddleName, r.LastName, r.Suffix)), r.Sex,
         TIMESTAMPDIFF(YEAR, r.BirthDate, CURDATE()), r.RelationshipToHead, r.CivilStatus,
         r.EducationLevel, r.TotalHouseholdIncome
  FROM residents r WHERE r.ResidentID = h OR r.FamilyHeadID = h;

  -- Officials (current term)
  INSERT INTO officials (ResidentID, Position, TermStart, TermEnd, ExpectedTermEnd, RecordedBy)
  SELECT ResidentID, 'Barangay Captain', '2023-11-30', '2026-11-30', '2026-11-30', 'Sample Data' FROM residents WHERE Email = 'juan.delacruz@example.com';
  INSERT INTO officials (ResidentID, Position, TermStart, TermEnd, ExpectedTermEnd, RecordedBy)
  SELECT ResidentID, 'Kagawad - Peace & Order', '2023-11-30', '2026-11-30', '2026-11-30', 'Sample Data' FROM residents WHERE Email = 'roberto.santos@example.com';
  INSERT INTO officials (ResidentID, Position, TermStart, TermEnd, ExpectedTermEnd, RecordedBy)
  SELECT ResidentID, 'Kagawad - Health & Sanitation', '2023-11-30', '2026-11-30', '2026-11-30', 'Sample Data' FROM residents WHERE Email = 'antonio.mendoza@example.com';
  INSERT INTO officials (ResidentID, Position, TermStart, TermEnd, ExpectedTermEnd, RecordedBy)
  SELECT ResidentID, 'Barangay Secretary', '2023-11-30', '2026-11-30', '2026-11-30', 'Sample Data' FROM residents WHERE Email = 'elena.reyes@example.com';
  INSERT INTO officials (ResidentID, Position, TermStart, TermEnd, ExpectedTermEnd, RecordedBy)
  SELECT ResidentID, 'SK Chairperson', '2023-11-30', '2026-11-30', '2026-11-30', 'Sample Data' FROM residents WHERE Email = 'paolo.delacruz@example.com';

  -- keep the ID generators in step with the new records
  -- (`last_value` is a reserved word in MySQL 8, so it must be in backticks)
  INSERT INTO id_sequences (`prefix`, `year`, `last_value`) VALUES ('RES', 2026, rs)
    ON DUPLICATE KEY UPDATE `last_value` = GREATEST(`last_value`, rs);
  INSERT INTO id_sequences (`prefix`, `year`, `last_value`) VALUES ('HH', 2026, hh)
    ON DUPLICATE KEY UPDATE `last_value` = GREATEST(`last_value`, hh);

  SELECT '30 sample residents, 8 households and 5 officials added.' AS result;
END seed$$
DELIMITER ;

CALL seed_sample_residents();
DROP PROCEDURE IF EXISTS seed_sample_residents;
