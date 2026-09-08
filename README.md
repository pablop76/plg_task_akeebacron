# Task – Akeeba Backup (cron)

Wtyczka zadań dla Joomla 5/6, która uruchamia kopie zapasowe **Akeeba Backup**
z poziomu wbudowanych **Zadań planowanych** (`System → Zadania planowane`).

Akeeba dostarcza własną wtyczkę zadań (`plg_task_akeebabackup`), ale **wyłącznie
w wersji Professional**. Ta wtyczka daje to samo posiadaczom wersji Core.

## Dlaczego nie nazywa się `akeebabackup`

Komponent Akeeba Backup trzyma listę rozszerzeń „tylko dla wersji Pro"
(`UpgradeModel::PRO_ONLY_EXTENSIONS`) i przy instalacji oraz **każdej aktualizacji**
wersji Core je odinstalowuje. `plg_task_akeebabackup` jest na tej liście, więc
wtyczka o takiej nazwie zniknęłaby razem z zadaniem przy najbliższej aktualizacji.
Stąd nazwa `akeebacron`.

## Wymagania

- Joomla 5.x lub 6.x
- Akeeba Backup dla Joomla (Core lub Professional), wersja 9 lub nowsza
- PHP 8.1+

## Instalacja

Zwykła instalacja paczki ZIP przez `System → Instaluj rozszerzenia`, następnie
włączenie wtyczki **„Zadanie – Akeeba Backup (cron)"** w menedżerze wtyczek.

## Budowanie paczki

```
php build.php
```

Skrypt czyta wersję i listę katalogów wprost z manifestu i zapisuje archiwum
w `dist/`. Do paczki trafia wyłącznie to, co deklaruje manifest — bez `README.md`,
`build.php` i historii gita.

Jeżeli CLI PHP nie ma włączonego rozszerzenia zip:

```
php -d extension_dir="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/ext" -d extension=php_zip.dll build.php
```

## Konfiguracja zadania

`System → Zadania planowane → Nowe → Akeeba Backup: wykonaj kopię`

| Ustawienie | Opis |
|---|---|
| **Profil kopii** | Profil Akeeba Backup do uruchomienia. To jego ustawienia decydują o zakresie kopii, miejscu zapisu archiwum i powiadomieniach. |
| **Kiedy wykonywać kopię** | `Gdy Akeeba zacznie przypominać o kopii` (domyślne), `Gdy ostatnia kopia jest starsza niż podany czas` albo `Przy każdym uruchomieniu zadania`. |
| **Dopuszczalny wiek ostatniej kopii** | Godziny, używane tylko przy własnym progu. |
| **Opis kopii** | Widoczny na liście kopii. Puste pole oznacza opis domyślny Akeeby. |
| **Limit czasu jednego przebiegu** | Sekundy. Po przekroczeniu zadanie zwraca `WILL_RESUME` i wznawia kopię przy kolejnym uruchomieniu. `0` wykonuje całą kopię w jednym przebiegu. |

### Próg świeżości z wtyczki quickicon

Akeeba nie ma własnego harmonogramu. Jedyny czas, jaki się w niej ustawia, to
`maxbackupperiod` we wtyczce `plg_quickicon_akeebabackup` — liczba **godzin**,
po których wizytówka w panelu robi się czerwona i prosi o kopię.

Przy domyślnym ustawieniu wtyczka czyta ten próg i pomija kopię, dopóki najnowsza
**ukończona** kopia jest od niego młodsza. Dzięki temu:

- czas kopii ustawia się w jednym miejscu, po stronie Akeeby;
- zadanie w Joomli można uruchamiać gęściej niż wynosi wymagana częstotliwość,
  więc przepadnięty przebieg zostaje nadrobiony przy najbliższej okazji, a nie
  dopiero po pełnym okresie.

Jeżeli wtyczka quickicon jest wyłączona, próg nie obowiązuje i kopia powstaje przy
każdym uruchomieniu zadania.

W odróżnieniu od quickicona liczy się tu wyłącznie kopia o statusie `complete` —
nieudana albo porzucona nie „zalicza się" i zadanie spróbuje ponownie.

## Kopie dłuższe niż jeden przebieg

Duża witryna nie zmieści się w jednym żądaniu. Po przekroczeniu limitu czasu
wtyczka zwraca `Status::WILL_RESUME`; Joomla przestawia wtedy `next_execution`
na „zaraz" i nie zwiększa licznika wykonań, a kolejny przebieg wznawia **tę samą**
kopię. Numer wznawianej kopii odczytywany jest z tabeli `#__akeebabackup_backups`
(najnowszy wpis o pochodzeniu `joomla` i statusie `run`). Kopia oznaczona jako
działająca dłużej niż dobę uznawana jest za porzuconą i zaczyna się nowa.

Kopie wykonane przez to zadanie mają pochodzenie **`joomla`**, które Akeeba
pokazuje na liście jako „Joomla Scheduled Tasks".

## Jak wyzwalać zadania Joomli

**Wtyczka nie uruchamia się sama.** Odpowiada tylko za to, *co* się dzieje, gdy
zadanie ruszy. O tym, *kiedy* rusza, decyduje wyzwalacz schedulera — Joomla nie ma
procesu chodzącego w tle, więc bez wyzwalacza zadanie leży w bazie i kopia nie
powstaje nigdy. Ten rozdział trzeba przejść przy wdrożeniu, inaczej reszta
konfiguracji nie ma znaczenia.

- **Cron z wiersza poleceń** (zalecane):
  `php /ścieżka/do/witryny/cli/joomla.php scheduler:run --all`
- **Webcron** — adres z kluczem, ustawiany w `Zadania planowane → Opcje`
- **Leniwy scheduler** — zadanie doczytuje się w żądaniach odwiedzających; przy
  kopiach ustaw wtedy niski limit czasu jednego przebiegu

Przy cronie z wiersza poleceń warto zaznaczyć zadaniu **„Tylko CLI"** i podnieść
limit czasu jednego przebiegu.

> `scheduler:run` kończy się **kodem wyjścia 123**, gdy kopia ma być wznowiona.
> To nie błąd, ale cron potraktuje to jak niepowodzenie i wyśle maila.

## Niezależność od Akeeba Ltd

To niezależna wtyczka. Nie jest tworzona, wspierana ani firmowana przez Akeeba Ltd.
„Akeeba" i „Akeeba Backup" to znaki towarowe Akeeba Ltd, użyte tu wyłącznie opisowo,
żeby wskazać, z czym wtyczka współpracuje.

Wtyczka nie zawiera kodu Akeeba Backup — korzysta w czasie działania z klas
zainstalowanego komponentu. Nie zastępuje też wersji Professional: daje jedną funkcję
harmonogramu, a nie pozostałe możliwości płatnego wydania.

## Licencja

GNU General Public License w wersji 3 lub nowszej — pełny tekst w [LICENSE.txt](LICENSE.txt).

Akeeba Backup jest rozpowszechniana na GPL w wersji 3 lub nowszej. Ta wtyczka działa
wyłącznie razem z nią, więc dzieli tę samą licencję, żeby nie było wątpliwości co do
zgodności obu części.
