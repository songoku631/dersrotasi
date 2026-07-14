# Ders Rotası Backend

Bu klasör Ders Rotası PHP API'sidir. Yapılandırma yalnızca bu klasördeki `.env` dosyasından veya production ortamındaki environment variable'lardan alınır.

## Gereksinimler

- PHP 8.2+
- Composer
- MySQL 8+
- Firebase Authentication projesi

## Yerel kurulum

```bash
cd derspilot/backend
composer install
```

Bu repoda yerel geliştirme için `.env` zaten `127.0.0.1:3306` üzerindeki MySQL'i hedefler. Yeni bir yerel kopyada `.env.example` dosyasını `.env` olarak kopyalayın ve yalnızca makinenize ait değerleri düzenleyin.

```bash
cp .env.example .env
php -S 127.0.0.1:8080 -t public
```

`FRONTEND_ORIGIN` CORS için kullanılan tek değişkendir. Vite yapılandırmasında sabit bir port tanımlı değildir; Vite hangi portta çalışıyorsa (varsayılan olarak çoğunlukla `5173`) `.env` içindeki `FRONTEND_ORIGIN` değerini o porta göre güncelleyin. Bu proje için beklenen yerel değer `http://localhost:5176`'dır.

## MySQL hazırlığı

MySQL kurulduktan sonra bir yönetici hesabıyla aşağıdakileri çalıştırın. Yerel `.env` varsayılanı `root` ve boş şifredir; farklı bir kullanıcı kullanırsanız `.env` içindeki `DB_USERNAME` ve `DB_PASSWORD` değerlerini de değiştirin.

```sql
CREATE DATABASE dersrotasi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dersrotasi'@'localhost' IDENTIFIED BY 'yerel-ve-guclu-bir-sifre';
GRANT ALL PRIVILEGES ON dersrotasi.* TO 'dersrotasi'@'localhost';
FLUSH PRIVILEGES;
```

Bu komutlar tamamlanıp `.env` güncellendikten sonra migration çalıştırılabilir:

```bash
composer migrate
# veya
php database/migrate.php
```

## Firebase

Mevcut token doğrulayıcısı Firebase ID token sertifikalarını Google'dan alır; bu nedenle doğrulama için `FIREBASE_PROJECT_ID=ders-rotasi` yeterlidir. `FIREBASE_CREDENTIALS_PATH` gelecekte Firebase Admin SDK gerektiren işlemler için ayrılmış güvenli bir yoldur ve yerelde boş bırakılır.

Bir service-account JSON dosyası gerektiğinde dosyayı repo dışında veya `backend/credentials/` altında tutun, `.env` içinde yalnızca mutlak dosya yolunu verin. Dosya `.gitignore` ile dışlanmıştır; JSON içeriğini `.env` dosyasına koymayın.

Korumalı endpointler `Authorization: Bearer <firebase_id_token>` başlığını bekler.

## Windows SSL / CA bundle

Windows yerel geliştirmede Firebase'in Google sertifika URL'sine yaptığı HTTPS isteği için güncel Mozilla CA bundle dosyasını `C:\php\cacert.pem` konumuna yerleştirin. `.env` içinde aşağıdaki değer yerel ortam için tanımlıdır:

```dotenv
SSL_CA_BUNDLE=C:\php\cacert.pem
```

PHP'nin yüklediği `php.ini`, `curl.cainfo`, `openssl.cafile`, PEM içeriği ve güvenli Google HTTPS isteğini tek komutla kontrol edin:

```bash
php scripts/check_ssl.php
```

`php.ini` veya CA dosyası değiştirildikten sonra çalışan `php -S` sürecini kapatıp yeniden başlatın. SSL doğrulamasını kapatmayın; `verify=false`, `CURLOPT_SSL_VERIFYPEER=false` ve benzeri seçenekler kullanılmamalıdır.

`SSL_CA_BUNDLE` yalnızca `APP_ENV=local` iken uygulanır. Cloud Run'da bu değişkeni tanımlamayın; Guzzle ve PHP sistem CA deposunu doğrulama açık biçimde kullanır.

## Production / Cloud Run

`.env` production'a taşınmamalı ve image içine kopyalanmamalıdır. Cloud Run'da `APP_ENV=production`, `APP_DEBUG=false`, production `FRONTEND_ORIGIN`, veritabanı değerleri ve `FIREBASE_PROJECT_ID` environment variable olarak tanımlanmalıdır. Veritabanı şifresi ile olası Firebase kimlik bilgisi Secret Manager üzerinden verilmelidir; service-account dosyası gerekirse Secret Manager ile volume olarak bağlanmalı ve `FIREBASE_CREDENTIALS_PATH` bu bağlama yolunu göstermelidir.

`FRONTEND_ORIGIN` boş bırakılırsa API CORS header'ı göndermez; production'da açık CORS (`*`) kullanılmaz.

## Endpointler

- `GET /health` — servis durumunu döndürür.
- `GET /api/me` — Firebase tokenını doğrular ve kullanıcıyı döndürür.
- `GET /api/profile` — giriş yapan kullanıcının profilini döndürür.
- `PUT /api/profile` — giriş yapan kullanıcının profilini kaydeder veya günceller.

### Üniversite tercih verisi

Üniversite, favori ve tercih tabloları `database/migrations/003_create_university_preference_system.sql` migrationında tanımlıdır. Migration otomatik çalışmaz. Veritabanı yedeği alındıktan ve SQL gözden geçirildikten sonra backend klasöründe elle çalıştırılmalıdır:

```bash
composer migrate
# veya
php database/migrate.php
```

İçe aktarma aracı yalnızca komut satırında çalışır. Gerçek 2025-YKS verisi, `storage/imports/universities_template.csv` başlıklarına göre hazırlanıp aşağıdaki komutla içe aktarılabilir:

```bash
php scripts/import_universities.php storage/imports/universities_2025.csv
```

Şablon sahte veya örnek program içermez. İçe aktarma aracı veriyi belleğe toplamaz; UTF-8/BOM ve virgül/noktalı virgül ayraçlarını destekler, zorunlu alanları ve izin verilen sabit değerleri doğrular, `program_code` üzerinden ekleme veya güncelleme yapar. Hatalı satırlar numarası ve nedeni ile raporlanır. Gerçek veri dosyası projeye eklenmemeli; `storage/imports/.gitignore` şablon dışındaki CSV dosyalarını dışarıda tutar.

Üniversite verileri geçmiş yerleştirme sonuçlarıdır. Nihai tercihler ÖSYM'nin güncel kılavuzundan kontrol edilmelidir. Canlı YÖK Atlas sayfa kazıma işlemi bu projede bulunmaz.

### 2025 başarı sırası eşleştirme

Başarı sırası dosyası, mevcut programları yalnızca `program_code + year` ile eşleştirir; yeni program oluşturmaz. Önce `database/migrations/004_add_university_rank_sources.sql` migrationını inceleyip normal migration komutuyla elle çalıştırın. Ardından resmî kaynaktan hazırlanmış dosyayı önce değişiklik yapmayan modda kontrol edin:

```bash
php scripts/import_university_ranks.php storage/imports/university_ranks_2025.csv --dry-run
```

Rapor uygun görülürse gerçek güncelleme açık onayla başlatılabilir:

```bash
php scripts/import_university_ranks.php storage/imports/university_ranks_2025.csv --apply
```

Komut, commit öncesinde `EVET` onayı ister. Etkileşimsiz ve kontrollü bir ortamda `--apply --yes` açık parametreleri kullanılabilir. Yalnızca `base_rank`, `rank_source_name`, `rank_source_url` ve `rank_updated_at` değiştirilebilir; puan, kontenjan ve program bilgileri değiştirilmez. JSON raporları `storage/reports/` altında oluşturulur ve Git tarafından yok sayılır.

### Kontrollü YÖK Atlas toplama aracı

13 Temmuz 2026 tarihinde yapılan incelemede YÖK Atlas'ın herkese açık React tercih sihirbazının, kimlik doğrulaması olmadan resmî `POST https://yokatlas.yok.gov.tr/api/tercih-kilavuz/search` kaynağını kullandığı doğrulandı. İstek gövdesi yalnızca yıl ve kılavuz/program kodu filtresi gönderir. Yanıttaki `basariSirasi` alanı kullanılır; ayrı bir koşul alanı olan `minBasariSirasi` hiçbir zaman başarı sırası olarak alınmaz.

Eski program detay bağlantıları kaynak sayfası olarak korunur:

- Lisans: `https://yokatlas.yok.gov.tr/lisans.php?y={program_code}`
- Önlisans: `https://yokatlas.yok.gov.tr/onlisans.php?y={program_code}`

HTML sayfa kazıma yapılmaz. Gizli/korumalı API, giriş, CAPTCHA veya erişim kontrolü kullanılmaz. `robots.txt` her çalıştırmada kontrol edilir. İnceleme tarihinde sunucu HTTP 200 dönmesine rağmen geçerli `User-agent`/`Disallow` yönergeleri yerine ilgisiz uygulama kaynak kodu döndürüyordu; araç bunu `no_valid_directives` olarak raporlar. Gelecekte resmi veri yolunu engelleyen geçerli bir kural görülürse araç çalışmayı durdurur.

Küçük ve değişiklik yapmayan kontrol:

```bash
php scripts/fetch_yokatlas_ranks.php --year=2025 --dry-run --limit=200
```

Tek program:

```bash
php scripts/fetch_yokatlas_ranks.php --year=2025 --program-code=105510829 --dry-run
```

Devam etme ve gerçek güncelleme:

```bash
php scripts/fetch_yokatlas_ranks.php --year=2025 --resume --apply
```

Varsayılan mod `dry-run`, filtre `only-missing`, toplam işleme limiti `100`, resmî sayfa boyutu `100` ve istek aralığı `1000 ms` değeridir. `--limit` sayfa boyutunu değil en fazla işlenecek program sayısını belirler. `--delay-ms` hiçbir zaman 1000'in altına inemez. `--apply` production ortamında reddedilir ve yerelde commit öncesinde `EVET` onayı ister. Mevcut dolu ve farklı `base_rank` değeri otomatik değiştirilmez.

Program kodu ve yıl birebir eşleşmesine ek olarak üniversite/bölüm adları normalize edilerek karşılaştırılır. Resmî `minPuan` ile mevcut ÖSYM `base_score` arasındaki mutlak fark `0.02` puanı aşarsa kayıt conflict sayılır ve yazılmaz.

Resmî endpoint 2025 verisini `page` ve `size` parametreleriyle sayfalı döndürür. Güvenli `size=100` değeriyle 21.602 program 217 veri isteğine bölünür. Her sayfa yalnızca bir kez indirilir, `storage/yokatlas/cache/page_2025_*_size_100.json` biçiminde cache'lenir ve programlar `program_code` anahtarlı yerel map üzerinden eşleştirilir. Böylece aynı program için ayrı HTTP isteği yapılmaz ve aynı anda bellekte yalnızca bir sayfa tutulur.

Sayfa cursor durumu `storage/yokatlas/state/bulk_resume_2025_{mod}.json`, JSON ve CSV raporları `storage/yokatlas/reports/` altında tutulur. Bu çalışma dosyaları Git tarafından yok sayılır. 429 yanıtında yeni istek yapılmadan işlem durur; 500/502/503 ve bağlantı hataları en fazla üç kez exponential backoff ile denenir. Ardışık hatalar güvenlik durdurmasını tetikler.

Dry-run ve apply cursor dosyaları ayrıdır. Cursor global sonuç indeksini saklar; limit sayfanın ortasında biterse resume aynı cache sayfasındaki sonraki programdan devam eder. Dry-run yapmak apply sırasını ilerletmez; apply cursor’u yalnızca veritabanı transactionı başarıyla commit edildikten sonra kaydedilir. Bu nedenle yarım kalan gerçek çalışma `--resume --apply` ile güvenli biçimde sürdürülebilir.

13 Temmuz 2026 tarihli iki sayfalık dry-run ölçümünde 200 program 2 veri isteğiyle işlendi. En az 1000 ms throttle beklemesini de içeren ortalama sayfa çağrısı `1.166` saniye oldu; 217 sayfalık tam çalışma yaklaşık `4.22` dakika olarak hesaplandı. Gerçek süre ağ ve resmî servisin yanıt hızına göre değişebilir.

Üniversite ve tercih endpointleri:

- `GET /api/universities`, `GET /api/universities/filters`, `GET /api/universities/{id}` — herkese açık arama, filtre ve detay.
- `GET|POST /api/favorites`, `DELETE /api/favorites/{universityId}` — kullanıcıya ait favoriler.
- `GET|POST /api/preferences`, `PUT|DELETE /api/preferences/{universityId}`, `PUT /api/preferences/reorder` — kullanıcıya ait tercih listesi, not ve sıralama.
- `GET /api/preference-suggestions` — geçmiş taban sırasına göre yaklaşık tercih grupları.

## Güvenlik notları

- `.env` Git tarafından yok sayılır; `.env.example` commit edilebilir ve gizli değer içermez.
- Firebase service-account dosyaları ve `credentials/` dizini Git tarafından yok sayılır.
- Gerçek veritabanı şifresi, Firebase private key'i veya service-account JSON içeriği commit edilmemelidir.

## YKS puan ve sıralama tahmini

Herkese açık `POST /api/yks/estimate` isteği netleri ve OBP katkısını resmî 2025 kurallarıyla hesaplar. Oturum açan kullanıcılar `POST /api/yks/estimates` ile sonucu kaydedebilir ve `GET /api/yks/estimates` ile son hesaplamalarını görebilir.

Kayıt özelliğini kullanmadan önce migration dosyalarını inceleyip elle çalıştırın:

```bash
composer migrate
```

Bu komut `database/migrations/006_create_yks_estimates.sql` dosyasını da çalıştırır. Migration bu görev sırasında otomatik çalıştırılmamıştır.

Tahmini ham puan; MEB OGM Materyal'in kamuya açık 2025 hesaplayıcısından kontrollü sonlu fark yöntemiyle ölçülen puan türü/test katsayılarıyla hesaplanır. 2023, 2024 ve 2025 ÖSYM test ortalama/standart sapmaları tarihsel puan aralığını oluşturur. Yerleştirme puanı, mevcut OBP katkısı eklenerek tahmin edilir. Başarı sırası aynı yıl ve puan türündeki dolu `universities.base_score + base_rank` noktaları arasında interpolasyonla bulunur; boş değerler kullanılmaz. Aralık, komşu program dağılımı ile tarihsel puan belirsizliğini birlikte yansıtır.

Bu model deterministiktir; rastgele veya sabit yüzde eklemez ve kesin ÖSYM sonucu iddiasında bulunmaz. Aynı puan türünde yeterli program noktası yoksa puan yine gösterilir, yalnızca sıralama bölümünün veri yetersiz olduğu açıklanır. Ayrıntılı yöntem, güven seviyesi ve kaynaklar `../docs/yks-score-and-rank-methodology.md` dosyasındadır.

Başarı sırası dönüşümünü gerçek yıllarla leave-one-year-out yöntemiyle denetlemek için salt-okunur backtest komutu kullanılabilir:

```bash
php scripts/backtest_yks_rank.php
```

Rapor `storage/reports/yks_rank_backtest_2025.json` dosyasına yazılır; veritabanında değişiklik yapmaz. Anlamlı doğrulama için her puan türünde en az üç farklı gerçek yıl ve 100 test örneği gerekir. Mevcut veritabanında yalnızca 2025 program sonuçları bulunduğundan hata metrikleri ölçülememiş ve kullanıcı güven seviyesi `Doğrulanmadı` olarak ayarlanmıştır. Sahte yıl veya program sonucu üretilmez.
