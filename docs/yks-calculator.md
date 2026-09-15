# YKS Puan ve Sıralama Hesaplama

`/yks-siralama-tahmini` artık doğru/yanlış girişinden beş puan türünü birlikte hesaplar. Önceki puan giriş ekranı ve ona özel CSS kaldırılmıştır. Backend hesaplama ve kayıt endpointleri değiştirilmemiştir.

## Kaynaklar ve desteklenen yıl

Dropdown 2026, 2025, 2024 ve 2023 yıllarını gösterir. **2025, 2024 ve 2023** seçilebilir; 2026 “hesaplama verisi henüz yok” etiketiyle disabled durumdadır. `src/config/yksYears.js` puan desteğini ve sıra veri yılını ayrı alanlarda tutar. Sıra dağılımının bulunması net–puan katsayısı bulunduğu anlamına gelmez.

- [ÖSYM 2025 kılavuzu duyurusu](https://www.osym.gov.tr/2025yuksekogretim-kurumlari-sinavi-yks-kilavuzu)
- [ÖSYM 2025 sayısal bilgiler](https://dokuman.osym.gov.tr/pdfdokuman/2025/YKS/sayisalbilgiler_tayd21072025.pdf): soru sayıları ve mevcut sıra dağılımlarının kaynağı.
- [MEB Giresun RAM 2025 YKS broşürü](https://giresunram.meb.k12.tr/meb_iys_dosyalar/28/01/151035/dosyalar/2025_02/12153907_2025yksbrosur.pdf): OBP alt sınırı, diploma dönüşümü ve puan türlerinin 0,5 net koşulları kontrol edildi.
- [MEB 2025 YKS öğrenci bilgilendirmesi](https://sariyerram.meb.k12.tr/meb_iys_dosyalar/34/17/231198/dosyalar/2024_11/01150335_2025yksogrencisunumu.pdf): normal ve yarıya düşen OBP katkısı.
- [MEB OGM Materyal](https://ogmmateryal.eba.gov.tr/yks-puan-hesaplama): mevcut backend kalibrasyonunun atıf yaptığı hesaplayıcı.
- [İşlevsel referans](https://ertansinansahin.com/yks-tyt-ayt-puan-hesaplama-ve-siralama-hesaplama/): yalnız giriş/sonuç akışı ve doğrusal hesaplama yaklaşımı incelendi; kod, metin, tasarım veya katsayı alınmadı.

`src/config/yksCoefficients.js`, 2023, 2024 ve 2025 için [yıl bazlı yayımlanmış tabloların](https://ertansinansahin.com/yks-tyt-ayt-puan-hesaplama-ve-siralama-hesaplama/) aktarılmış değerlerini içerir. Tablolardaki TYT, SAY, EA, SÖZ ve DİL başlangıç puanlarıyla her dersin katsayısı yıl ve ders bazında tek tek denetlendi. Önceki MEB OGM ölçüm seti, kaynak tablosundaki 2025 değerleriyle aynı değildi; bu nedenle 2025 de kaynak tablosundaki yıl etiketli değerlerle değiştirildi. Tablo sayıları yuvarlanmış biçimde yayımlandığı için araç sonucu resmî sonuç belgesi yerine referans hesap olarak sunulur.

ÖSYM 2023–2026 sayısal bilgi tabloları test ortalama/standart sapmaları ve yığınsal sıra verisi içerir. Bunlar tek başına başlangıç sabitini ve nihai net başına puan katsayılarını vermediği için 2026'yı açmak için kullanılmadı. Projenin üniversite kataloğundaki 2026 alanları da katsayı seti değildir. Unit testler uygulamayı sınar, gerçek ÖSYM puanlarına uygunluğu kanıtlamaz.

## Hesaplama

- Net = doğru − yanlış / 4. Boş ders alanları 0; negatif net korunur. Negatif, kesirli veya soru sayısını aşan cevap adetleri reddedilir.
- OBP = max(50, diploma notu) × 5. Boş diploma 0 olarak işlenir ve kurala göre 50'ye yükseltilir; kullanıcıya bu davranış açıklanır. Normal katkı OBP × 0,12; kırık katkı OBP × 0,06. Özel yetenek/mesleki ek puan gibi özel durumlar kapsam dışıdır.
- Tahmini ham puan = türün başlangıç puanı + Σ(net × yılın ders katsayısı). Mevcut kalibrasyondaki 100–500 sınırı korunur. Yerleştirme puanı, yuvarlanmamış ham puana OBP katkısı eklenerek hesaplanır; sonuçlar üç ondalığa yuvarlanır.
- TYT Türkçe veya Temel Matematik neti en az 0,5 olmalıdır. SAY için AYT Matematik veya toplam Fen; EA için Matematik veya Edebiyat–Sosyal-1; SÖZ için Edebiyat–Sosyal-1 veya Sosyal-2; DİL için YDT koşulu uygulanır. Fen ve sosyal alt derslerinde netler test bazında toplanır. Koşulu karşılamayan tür için puan uydurulmaz.
- Ortak AYT dersleri tek state kaydında tutulur. Alan düğmeleri yalnız görünen dersleri değiştirir; tüm türler hesaplanır. Toplam AYT netinde ortak dersler bir kez sayılır, tür bazlı AYT netleri ayrıca gösterilir.

## Sıra aralığı

Mevcut `/api/yks/rank-band` servisinde 2023, 2024, 2025 ve 2026 için SAY, EA, SÖZ ve DİL sınav/yerleştirme sıra dağılımları vardır. 2023–2025 kayıtları korundu. 2026, [ÖSYM 2026 sayısal bilgiler PDF](https://dokuman.osym.gov.tr/pdfdokuman/2026/YKS/SB/sayisal_ykdd21072026.pdf) sayfa 12–13 tablolarından eklendi. Bu yayın, 2026 sınav sonuçlarının mevcut olduğunu doğrular; katsayıların da doğrulandığı anlamına gelmez.

Frontend geçerli **tahmini yerleştirme puanını** gönderir; `scoreYear` ile `rank.datasetYear` aynı değilse sıra göstermez. Yanıtta o yıl yoksa önceki/sonraki yıla geri dönüş yapılmaz. Şu anda yalnız 2025 puan hesabı seçilebildiği için ekranda yalnız 2025 sıra aralığı gösterilir; diğer yılların veri desteği serviste hazırdır. Dağılım eşikleri arasında merkez sıra veya interpolasyon üretilmez. Bu aralık puan tahmininin belirsizliğini içeren istatistiksel bir güven aralığı değildir; gerçek başarı sırası olarak sunulmaz.

Servisin TYT dağılımı yoktur; TYT puanları hesaplanır fakat sırası açıkça mevcut değil olarak gösterilir. Servis hatasında frontend puan sonuçları korunur. Form değişikliği eski sonucu temizler ve bekleyen istekleri iptal eder. API/backend'in başka ekranlarda kullanılabilecek ortak kodlarına dokunulmamıştır.

## Yeni yıl ekleme ve test

Yeni yıl için kaynakları, başlangıç puanlarını, ders katsayılarını ve OBP kurallarını `yksCoefficients` kaydına ekleyin; soru sayıları ve uygunluk kurallarını da yeniden doğrulayın. Seçenekler kayıtlardan otomatik oluşur. Sıralama servisine aynı yılın dağılımı eklenmeden o yıl için sıra gösterilmez. 2025 tercih bağlantısı başka yıla taşınmaz.

`tests/yksCalculator.test.mjs`: net/OBP, 2023/2024/2025'in her biri için beş puan türünde sabit karma doğru–yanlış örnekleri, kırık OBP, tam net sınırları, boş sınav, uygunluk koşulları, ortak dersler, geçersiz girişler ve desteklenmeyen yıllar.

Komutlar: `npm.cmd run test:frontend`, `npm.cmd run lint`, `npm.cmd run build`.
