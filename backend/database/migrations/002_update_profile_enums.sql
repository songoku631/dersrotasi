ALTER TABLE user_profiles
  MODIFY score_type VARCHAR(32) NOT NULL DEFAULT 'sayisal';

UPDATE user_profiles
SET score_type = CASE score_type
  WHEN 'Sayısal' THEN 'sayisal'
  WHEN CONVERT(0x536179C384C2B173616C USING utf8mb4) COLLATE utf8mb4_unicode_ci THEN 'sayisal'
  WHEN 'Eşit Ağırlık' THEN 'esit_agirlik'
  WHEN CONVERT(0x45C385C5B869742041C384C5B8C384C2B1726CC384C2B16B USING utf8mb4) COLLATE utf8mb4_unicode_ci THEN 'esit_agirlik'
  WHEN 'Sözel' THEN 'sozel'
  WHEN CONVERT(0x53C383C2B67A656C USING utf8mb4) COLLATE utf8mb4_unicode_ci THEN 'sozel'
  WHEN 'Dil' THEN 'dil'
  WHEN 'sayisal' THEN 'sayisal'
  WHEN 'esit_agirlik' THEN 'esit_agirlik'
  WHEN 'sozel' THEN 'sozel'
  WHEN 'dil' THEN 'dil'
  ELSE 'sayisal'
END;

ALTER TABLE user_profiles
  MODIFY score_type ENUM('sayisal', 'esit_agirlik', 'sozel', 'dil')
  NOT NULL DEFAULT 'sayisal';

ALTER TABLE user_profiles
  MODIFY university_type VARCHAR(32) NOT NULL DEFAULT 'fark_etmez';

UPDATE user_profiles
SET university_type = CASE university_type
  WHEN 'Devlet' THEN 'devlet'
  WHEN 'devlet' THEN 'devlet'
  WHEN 'Vakıf' THEN 'vakif'
  WHEN CONVERT(0x56616BC384C2B166 USING utf8mb4) COLLATE utf8mb4_unicode_ci THEN 'vakif'
  WHEN 'vakif' THEN 'vakif'
  WHEN 'Fark etmez' THEN 'fark_etmez'
  WHEN 'fark_etmez' THEN 'fark_etmez'
  ELSE 'fark_etmez'
END;

ALTER TABLE user_profiles
  MODIFY university_type ENUM('devlet', 'vakif', 'fark_etmez')
  NOT NULL DEFAULT 'fark_etmez';
