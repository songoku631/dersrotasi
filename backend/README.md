# Ders Rotası Backend

Bu klasör Ders Rotası PHP API'sidir. Yapılandırma yalnızca bu klasördeki `.env` dosyasından veya production ortamındaki environment variable'lardan alınır.

## Gereksinimler

- PHP 8.2+
- Composer
- MySQL 8+
- Firebase Authentication projesi
- Backend testleri için PDO SQLite (`backend/Dockerfile` içinde kurulur)

## Yerel kurulum

```bash
cd derspilot/backend
composer install
```

Bu repoda yerel geliştirme için `.env` zaten `127.0.0.1:3306` üzerindeki MySQL'i hedefler. Yeni bir yerel kopyada `.env.example` dosyasını `.env` olarak kopyalayın ve yalnızca makinenize ait değerleri düzenleyin.

```bash
cp .env.example .env
php -S 127.0.0.1:8000 -t public
```

`FRONTEND_ORIGIN` CORS için kullanılan tek değişkendir. Vite geliştirme sunucusu `5173` portunu kullanır; `.env` içindeki `FRONTEND_ORIGIN` değeri de `http://localhost:5173` olmalıdır.

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

### AI rate limit migration'ı

AI endpoint'i rate limit durumunu bütün Cloud Run instance'ları arasında paylaşmak için Cloud SQL'deki `ai_rate_limits` tablosunu kullanır. `identifier_hash` alanında yalnızca SHA-256 özeti tutulur; Firebase UID veya IP gibi ham anahtarlar veritabanına yazılmaz. Yeni backend revision'ını yayınlamadan önce production veritabanı yedeğini alıp migration'ı çalıştırın:

```bash
cd backend
APP_ENV=production php database/migrate.php
```

Bu komut idempotent `database/migrations/007_create_ai_rate_limits.sql` dosyasını da uygular. Tablo eksikse veya Cloud SQL erişilemezse AI endpoint'i limitsiz çalışmak yerine güvenli biçimde HTTP 503 döndürür.

### OPENAI_API_KEY Secret Manager bağlantısı

OpenAI API anahtarını `.env`, Cloud Build substitution, Docker build argument veya repodaki herhangi bir dosyaya yazmayın. OpenAI de production anahtarlarının kaynak kod yerine environment variable veya bir secret yönetim servisiyle verilmesini önerir. Aşağıdaki komutlar Google Cloud Shell/Bash içindir; `PROJECT_ID`, servis hesabı ve secret version yer tutucularını kendi production değerlerinizle değiştirin.

Önce Secret Manager API'yi etkinleştirip boş secret kaydını oluşturun:

```bash
gcloud config set project PROJECT_ID
gcloud services enable secretmanager.googleapis.com
gcloud secrets create dersrotasi-openai-api-key --replication-policy=automatic
```

Anahtarı terminal çıktısına veya shell history'ye yazmadan yeni secret version olarak ekleyin:

```bash
read -rsp 'OpenAI API key: ' OPENAI_API_KEY_VALUE
printf '%s' "$OPENAI_API_KEY_VALUE" | \
  gcloud secrets versions add dersrotasi-openai-api-key --data-file=-
unset OPENAI_API_KEY_VALUE
```

Backend servisinin kullandığı service account e-postasını öğrenin:

```bash
gcloud run services describe dersrotasi-backend \
  --region=europe-west1 \
  --format='value(spec.template.spec.serviceAccountName)'
```

Çıktıdaki hesabı `BACKEND_SERVICE_ACCOUNT_EMAIL` yerine koyup yalnızca bu secret için erişim verin:

```bash
gcloud secrets add-iam-policy-binding dersrotasi-openai-api-key \
  --member='serviceAccount:BACKEND_SERVICE_ACCOUNT_EMAIL' \
  --role='roles/secretmanager.secretAccessor'
```

Son olarak oluşturulan sayısal secret version'ı `SECRET_VERSION` yerine yazarak Cloud Run environment variable'ına bağlayın. Production'da `latest` yerine sabit version kullanın:

```bash
gcloud run services update dersrotasi-backend \
  --region=europe-west1 \
  --update-secrets=OPENAI_API_KEY=dersrotasi-openai-api-key:SECRET_VERSION \
  --update-env-vars=AI_CHAT_ENABLED=true,OPENAI_MODEL=gpt-5.6-luna,OPENAI_TIMEOUT=25
```

Anahtar döndürüldüğünde Secret Manager'a yeni version ekleyin ve Cloud Run servisini yeni sayısal version'a güncelleyin. Eski version'ı ancak yeni revision doğrulandıktan sonra devre dışı bırakın.

Kaynaklar: [OpenAI production API key önerileri](https://developers.openai.com/api/docs/guides/production-best-practices#api-keys), [Cloud Run secret yapılandırması](https://cloud.google.com/run/docs/configuring/services/secrets), [Secret Manager'a version ekleme](https://cloud.google.com/secret-manager/docs/add-secret-version).

## Endpointler

- `GET /health` — servis durumunu döndürür.
- `GET /api/me` — Firebase tokenını doğrular ve kullanıcıyı döndürür.
- `GET /api/profile` — giriş yapan kullanıcının profilini döndürür.
- `PUT /api/profile` — giriş yapan kullanıcının profilini kaydeder veya günceller.
- `POST /api/ai/chat` — Firebase ile doğrulanmış kullanıcıya veritabanı destekli AI yanıtı döndürür.

### Üniversite tercih verisi

Üniversite, favori ve tercih tabloları `database/migrations/003_create_university_preference_system.sql` migrationında tanımlıdır. Migration otomatik çalışmaz. Veritabanı yedeği alındıktan ve SQL gözden geçirildikten sonra backend klasöründe elle çalıştırılmalıdır:

```bash
composer migrate
# veya
php database/migrate.php
```

İçe aktarma aracı yalnızca komut satırında çalışır. Güncel resmî YÖK Atlas tercih kılavuzu önce değişiklik yapmayan küçük bir dry-run ile doğrulanabilir. Export aracı resmî yanıttaki yılı ayrıca kontrol eder ve farklı bir yılı yanlış etiketlemez:

```bash
php scripts/fetch_yokatlas_universities.php --dry-run --year=2026 --limit=100
php scripts/fetch_yokatlas_universities.php --write --year=2026 --limit=25000
```

Doğrulanan CSV, `storage/imports/universities_template.csv` başlıklarına göre hazırlanır ve mevcut transaction destekli importer ile yerel veritabanına aktarılabilir:

```bash
php scripts/import_universities.php storage/imports/universities_2026.csv
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
