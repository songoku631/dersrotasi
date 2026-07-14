# Ders Rotası

YKS öğrencileri için net, puan, sıralama, pomodoro ve çalışma planı araçlarını tek arayüzde toplamayı hedefleyen React frontend prototipi.

## Komutlar

```bash
npm install
npm run dev
npm run build
npm run preview
npm run lint
```

PowerShell çalıştırma ilkesi `npm` komutunu engellerse Windows üzerinde `npm.cmd` kullanabilirsiniz:

```bash
npm.cmd run dev
```

## Yapı

```text
src/
  components/
  data/
  layouts/
  pages/
  styles/
```

React Router DOM ile tüm sayfalar `BrowserRouter` altında tanımlıdır. `public/_redirects` ve `public/.htaccess`, statik hosting ve Apache dağıtımlarında sayfa yenileme sonrası SPA yönlendirmelerinin bozulmaması için eklenmiştir.
