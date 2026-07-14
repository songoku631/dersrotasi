-- Yalnızca boş öğretim dili değerlerini tamamlar. Açık yabancı dil veya
-- hazırlık ibaresi taşıyan bölüm adları, veri kaynağında doğrulanmaları için korunur.
UPDATE universities
SET education_language = 'Türkçe',
    updated_at = CURRENT_TIMESTAMP
WHERE (education_language IS NULL OR TRIM(education_language) = '')
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%İngilizce%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Ingilizce%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%İngiliz%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Ingiliz%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%English%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Almanca%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Fransızca%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Fransizca%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Fransız%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Fransiz%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Arapça%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Arapca%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Rusça%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Rusca%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%İspanyolca%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Ispanyolca%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%İtalyanca%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Italyanca%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Çince%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Cince%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Korece%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Hazırlık%'
  AND department_name COLLATE utf8mb4_unicode_ci NOT LIKE '%Hazirlik%';
