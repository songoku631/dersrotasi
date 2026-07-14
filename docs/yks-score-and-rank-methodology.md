# YKS puan ve sıralama tahmini yöntemi

## Resmî ve doğrulanabilir kaynaklar

- [ÖSYM 2025-YKS Kılavuzu](https://dokuman.osym.gov.tr/pdfdokuman/2025/YKS/kilavuz11062025.pdf)
- [ÖSYM 2025-YKS sayısal bilgiler](https://dokuman.osym.gov.tr/pdfdokuman/2025/YKS/sayisalbilgiler_tayd21072025.pdf)
- [ÖSYM 2024-YKS Kılavuzu](https://dokuman.osym.gov.tr/pdfdokuman/2024/YKS/kilavuz_d23052024.pdf)
- [ÖSYM 2024-YKS sayısal bilgiler](https://dokuman.osym.gov.tr/pdfdokuman/2024/YKS/sayisalbilgiler16072024.pdf)
- [ÖSYM 2023-YKS Kılavuzu](https://dokuman.osym.gov.tr/pdfdokuman/2023/YKS/kilavuz_30032023.pdf)
- [ÖSYM 2023-YKS sayısal bilgiler](https://dokuman.osym.gov.tr/pdfdokuman/2023/YKS/sayisalbilgiler20072023.pdf)
- [MEB OGM Materyal YKS Puan Hesaplama](https://ogmmateryal.eba.gov.tr/yks-puan-hesaplama)

ÖSYM kılavuzlarından soru sayıları, `doğru - yanlış / 4` net kuralı, test ağırlıkları ve OBP kuralları alınır. ÖSYM sayısal bilgi belgelerinden 2023, 2024 ve 2025 test ortalamaları ile standart sapmaları alınır. Projenin `universities` tablosundan yalnızca 2025 yılına ait, aynı puan türündeki `base_score` ve `base_rank` çiftleri sıralama dönüşümünde kullanılır.

## Net ve OBP

Her testin neti `doğru - yanlış / 4` formülüyle hesaplanır. Doğru ve yanlış toplamı soru sayısını aşamaz. Doğrudan net girişinde değer 0 ile testin soru sayısı arasında olmalıdır.

Diploma notu 5 ile çarpılarak OBP bulunur. Diploma notu 50'nin altındaysa hesaplamada 50 kullanılır. Normal katkı `OBP × 0,12`, bir önceki yıl merkezî yerleştirmeyle yerleşen aday için `OBP × 0,06` değeridir. Bu bölüm tahmin değil, yapılandırılmış kuralın deterministik sonucudur.

## Netten tahmini ham puana geçiş

Merkez 2025 tahmini şu doğrusal yapıdadır:

```text
tahmini ham puan = puan türü referans sabiti + Σ(test neti × test katsayısı)
tahmini yerleştirme puanı = tahmini ham puan + OBP katkısı
```

Referans sabitleri ve katsayılar ÖSYM tarafından yayımlanmış nihai dönüşüm katsayıları değildir. MEB OGM Materyal'in kamuya açık 2025 hesaplayıcısına nötr başlangıç girdisi ve her test için ayrı kontrollü bir net artışı verilerek 14 Temmuz 2026 tarihinde sonlu fark yöntemiyle ölçülmüştür. Bu nedenle katsayılar rastgele veya elle uydurulmuş değildir; ancak MEB hesaplayıcısının iç formülü yayımlanmadığından bağımsız bir resmî ÖSYM formülü olarak doğrulanmış da değildir. Arayüz sonucu kesin puan olarak sunmaz.

2023, 2024 ve 2025 için üç zorluk senaryosu üretilir. Her testte kullanıcının netinin ilgili yılın resmî ortalamasından uzaklığı, o yıl ile 2025 standart sapmalarının oranıyla 2025 ölçeğine taşınır:

```text
yıl senaryosu = 2025 referans ortalama puanı
  + Σ(katsayı × 2025 standart sapması / yıl standart sapması × (net - yıl ortalaması))
```

Bu taşıma formülü uygulamanın açık varsayımıdır; ÖSYM tarafından yayımlanmış bir nihai puan formülü değildir. Tahmini ham puan aralığı üç senaryonun en düşük ve en yüksek sonucudur. Sabit `±%10` veya rastgele pay kullanılmaz. DİL hesabında, formda sınav dili seçilmediği için İngilizce YDT istatistikleri referans alınır.

## Puandan tahmini başarı sırasına geçiş

SAY yalnızca `say`, EA yalnızca `ea`, SÖZ yalnızca `soz`, DİL yalnızca `dil`, TYT yalnızca `tyt` program kayıtlarıyla eşleştirilir. Yıl `2025` ile sınırlandırılır. Boş veya geçersiz `base_score`/`base_rank` kayıtları kullanılmaz.

Aynı taban puana sahip programların başarı sıralarında ortanca değer alınarak tek bir eğri noktası hazırlanır. Kullanıcının yerleştirme puanı iki nokta arasındaysa doğrusal interpolasyon yapılır. Tahmin aralığı yakın puanlardaki başarı sırası yayılımı, komşu sıra farklarının ortancası ve 2023–2025 puan senaryolarının alt/üst puanlara yansımasıyla oluşturulur. Puan veri aralığının dışındaysa en yakın nokta kullanılır ve aralık genişletilir.

Sıralama dönüşümünde üniversitelerin taban puanı ve başarı sırası dışında üniversite alanı kullanılmaz. Netten puana geçişte ise ÖSYM test ortalama/standart sapmaları ve MEB OGM referans hesaplayıcısı kullanılır. Üniversite taban değerleri bütün aday dağılımı değil, yalnızca programlara yerleşen son adayların geçmiş sonuçlarıdır.

## Backtest yöntemi

`php scripts/backtest_yks_rank.php` komutu veritabanını değiştirmeden leave-one-year-out doğrulaması yapar:

1. Her puan türü ayrı tutulur.
2. Bir yılın bütün gerçek program puan–sıra çiftleri test kümesi olarak dışarıda bırakılır.
3. Kalan yıllardan puan–sıra eğrisi hazırlanır.
4. Dışarıda bırakılan yılın her programı tahmin edilir ve gerçek `base_rank` ile karşılaştırılır.
5. Yıllar sırayla dışarıda bırakılarak örnekler birleştirilir.

Rapor her puan türü için örnek sayısı, ortanca mutlak sıra hatası, ortalama mutlak yüzde hata, tahmin aralığının gerçek sonucu kapsama oranı ile en iyi/en kötü örneği içerir. Anlamlı doğrulama için en az üç farklı yıl ve puan türü başına en az 100 test örneği gerekir.

Bu backtest yalnızca yerleştirme puanından başarı sırasına geçişi sınar. Aday bazında gerçek net, ham puan, yerleştirme puanı ve başarı sırasını birlikte içeren resmî mikro veri bulunmadığı için netten puana kalibrasyonunu uçtan uca doğrulayamaz.

## Mevcut backtest sonucu

14 Temmuz 2026 tarihli yerel veri denetiminde kullanılabilir kayıtların tamamı 2025 yılına aittir:

| Puan türü | Kullanılabilir 2025 puan–sıra çifti | Backtest örneği | Ortanca mutlak hata | Ortalama yüzde hata | Aralık kapsama oranı | Güven |
|---|---:|---:|---:|---:|---:|---|
| SAY | 4.416 | 0 | Ölçülemedi | Ölçülemedi | Ölçülemedi | Doğrulanmadı |
| EA | 3.181 | 0 | Ölçülemedi | Ölçülemedi | Ölçülemedi | Doğrulanmadı |
| SÖZ | 1.548 | 0 | Ölçülemedi | Ölçülemedi | Ölçülemedi | Doğrulanmadı |
| DİL | 563 | 0 | Ölçülemedi | Ölçülemedi | Ölçülemedi | Doğrulanmadı |
| TYT | 8.284 | 0 | Ölçülemedi | Ölçülemedi | Ölçülemedi | Doğrulanmadı |

Tek yılın tamamını dışarıda bıraktığımızda eğitim için başka gerçek yıl kalmadığından tahmin, hata oranı, en iyi veya en kötü örnek üretilemez. Sahte geçmiş yıl verisi eklenmemiştir.

## Güven seviyesi

Yerel komşu sayısı veya tarihsel puan yayılımı artık kullanıcıya `Yüksek`, `Orta` ya da `Düşük` güven etiketi vermek için kullanılmaz. Bu bilgiler yalnızca aralığın oluşturulmasına yardımcı olur.

Bir güven etiketi ancak backtest yeterli veriyle tamamlanırsa türetilir. Merkezi doğrulama politikası:

- Ortalama yüzde hata en fazla `%15` ve kapsama en az `%80` ise `Yüksek`.
- Ortalama yüzde hata en fazla `%30` ve kapsama en az `%60` ise `Orta`.
- Yeterli backtest yapılıp bu eşikler sağlanmazsa `Düşük`.
- Backtest yapılamazsa veya örnek sayısı yetersizse `Doğrulanmadı`.

Bu eşikler resmî ÖSYM eşikleri değil, uygulamanın önceden tanımlanmış doğrulama politikasıdır. Mevcut tek-yıllı veri nedeniyle arayüz `Doğrulanmadı` gösterir.

## 2026 ve diğer sınırlamalar

- 2026 sınav sonuçları, aday ortalamaları, standart sapmalar ve yerleştirme sonuçları açıklanmadan sonuç `2026 sıralaması` olarak sunulamaz. Arayüz açıkça `2025 verilerine göre tahmin` yazar.
- Sınav zorluğu, aday dağılımı, kontenjanlar ve tercih davranışları her yıl değişir.
- MEB OGM referans aracının yöntemi değişirse katsayılar yeniden denetlenmelidir.
- Geçmiş program taban puanları aday bazında net–puan–sıra örnekleri değildir.
- Veri aralığı dışındaki sonuçlarda aşırı kesin ekstrapolasyon yapılmaz.
- Bu hesaplama geçmiş YKS verilerine dayalı bir tahmindir; kesin ÖSYM sonucu veya yerleşme olasılığı değildir.

## Yeni yıl ekleme

1. İlgili yılın ÖSYM kılavuzu ve sayısal bilgi belgesi doğrulanır.
2. Yılın resmî test ortalama/standart sapmaları kaynak bağlantısıyla yapılandırmaya eklenir.
3. Aynı yıla ait gerçek program `base_score` ve `base_rank` verileri yüklenir.
4. Backtest yeniden çalıştırılır; rapor yeterli değilse güven `Doğrulanmadı` kalır.
5. Yeni yıl, sonuçları açıklanmadan kullanıcıya o yılın sıralaması olarak gösterilmez.
