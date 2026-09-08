# Task – Akeeba Backup (cron)

Wtyczka zadań dla Joomla 5/6, która uruchamia kopie zapasowe **Akeeba Backup**
z poziomu wbudowanych **Zadań planowanych** (`System → Zadania planowane`).

Akeeba dostarcza własną wtyczkę zadań (`plg_task_akeebabackup`), ale **wyłącznie
w wersji Professional**. Ta wtyczka daje to samo posiadaczom wersji Core.

## Wymagania

- Joomla 5.x lub 6.x
- Akeeba Backup dla Joomla (Core lub Professional), wersja 9 lub nowsza
- PHP 8.1+

---

# Jak uruchomić automatyczne kopie

Joomla nie ma własnego zegara. Zadanie leży w bazie i czeka, aż coś je **szturchnie**.
Wtyczka wykonuje kopię dopiero po takim szturchnięciu — odpowiada za to, *co* się
dzieje, a nie za to, *kiedy*. Dlatego oprócz utworzenia zadania trzeba jeszcze
zdecydować, kto ma je szturchać.

## Krok 1: wgraj i włącz wtyczkę

1. `System → Instaluj rozszerzenia` → wgraj paczkę `plg_task_akeebacron-*.zip`
2. `System → Wtyczki` → wpisz w wyszukiwarkę `akeebacron` → przełącz na **włączoną**

Kolejne wersje przychodzą już przez `System → Aktualizacje`. Wtyczka zgłasza się do
serwera aktualizacji na GitHubie, więc paczki nie trzeba wgrywać ręcznie drugi raz.

## Krok 2: utwórz zadanie

`System → Zadania planowane → Nowe → Akeeba Backup: wykonaj kopię`

Ustaw profil kopii i godzinę (na przykład codziennie 03:00), zapisz. Pozostałe pola
opisuje [tabela niżej](#ustawienia-zadania) — domyślne wartości są dobre na start.

## Krok 3: wybierz, co ma szturchać Joomlę

Są trzy sposoby. **Pierwszy działa od razu, bez konfiguracji.**

### Leniwy scheduler (domyślny, nic nie trzeba robić)

Joomla szturcha się sama, skryptem doładowanym w przeglądarce odwiedzającego. Jest
włączony domyślnie, więc kopie zaczną powstawać zaraz po wykonaniu kroków 1 i 2.

Wady, przez które nie nadaje się na poważną produkcję:

- działa tylko wtedy, gdy ktoś wejdzie na stronę, więc witryna bez ruchu nie zrobi kopii
- kopia idzie w zwykłym żądaniu PHP, obowiązuje więc limit czasu wykonania skryptu
- między szturchnięciami mija domyślnie 5 minut, przez co kopia dzielona na kilkanaście
  kawałków potrafi ciągnąć się godzinami

Przy tym wariancie zostaw **limit czasu jednego przebiegu** na 30 sekund.

### Cron z wiersza poleceń (zalecany)

Kopia rusza o stałej porze, jednym ciągiem i niezależnie od ruchu. Jedna linijka
w panelu hostingu, uruchamiana na przykład co 15 minut:

```
/usr/local/bin/php83 /home/UŻYTKOWNIK/domains/TWOJA-DOMENA/public_html/cli/joomla.php scheduler:run --all
```

Ścieżkę do PHP i do katalogu witryny odczytasz w panelu swojego hostingu.

Gdy cron już działa, wróć do zadania i ustaw:

- **Limit czasu jednego przebiegu** na `0` — kopia pójdzie jednym ciągiem
- **Uruchamiaj tylko z wiersza poleceń** na `Tak` — kopia przestanie ruszać
  z ruchu odwiedzających i z webcrona

> Drugie pole dokłada ta wtyczka. Joomla trzyma to ustawienie w kolumnie
> `cli_exclusive` tabeli `#__scheduler_tasks` i respektuje je od wersji 4.1, ale
> własnego pola w formularzu zadania nie ma — bez wtyczki dałoby się je włączyć
> wyłącznie zapytaniem SQL.

Efekt obejmujący całą witrynę daje `Zadania planowane → Opcje → Planowanie
z opóźnieniem` ustawione na **Wyłączone**. Wtedy na cron czekają wszystkie
zadania, nie tylko kopia.

> `scheduler:run` kończy się **kodem wyjścia 123**, gdy kopia ma być wznowiona.
> To nie błąd, ale cron potraktuje to jak niepowodzenie i wyśle maila.

### Webcron

Adres z kluczem, wywoływany przez zewnętrzną usługę. Włącza się w
`Zadania planowane → Opcje`. Sensowny, gdy hosting nie daje crona.

## Krok 4: sprawdź, że działa

- `System → Zadania planowane` — kolumny z datą ostatniego uruchomienia i licznikiem błędów
- `Akeeba Backup → Zarządzaj kopiami` — kopie z tego zadania mają pochodzenie
  **„Joomla Scheduled Tasks"**
- `System → Dzienniki` albo plik `administrator/logs/joomla_scheduler.php` — tam trafia
  każde uruchomienie wraz z powodem pominięcia kopii

## Jak często będą powstawać kopie

Uwaga, bo to najczęstsze zaskoczenie: **godzina w zadaniu nie ustala częstotliwości kopii.**
Zadanie uruchamia się o wskazanej porze, ale kopię wykona dopiero wtedy, gdy poprzednia
się zestarzeje. Próg bierze się z wtyczki quickicon Akeeby i domyślnie wynosi **24 godziny**.

Chcesz kopię codziennie? Zostaw 24. Chcesz co tydzień? Wpisz 168. Ustawia się to
w `System → Wtyczki`, wtyczka `Quick Icon - Akeeba Backup`, pole z maksymalnym
wiekiem kopii w godzinach (w kodzie `maxbackupperiod`).
Szczegóły niżej, w [Próg świeżości](#próg-świeżości-z-wtyczki-quickicon).

Jeśli wolisz sterować częstotliwością wprost z zadania, przestaw **Kiedy wykonywać kopię**
na `Gdy ostatnia kopia jest starsza niż podany czas` i podaj liczbę godzin.

---

# Ustawienia zadania

| Ustawienie | Opis |
| --- | --- |
| **Profil kopii** | Profil Akeeba Backup do uruchomienia. To jego ustawienia decydują o zakresie kopii, miejscu zapisu archiwum i powiadomieniach. |
| **Kiedy wykonywać kopię** | `Gdy Akeeba zacznie przypominać o kopii` (domyślne), `Gdy ostatnia kopia jest starsza niż podany czas` albo `Przy każdym uruchomieniu zadania`. |
| **Dopuszczalny wiek ostatniej kopii** | Godziny, używane tylko przy własnym progu. |
| **Opis kopii** | Widoczny na liście kopii. Puste pole oznacza opis domyślny Akeeby. |
| **Limit czasu jednego przebiegu** | Sekundy. Po przekroczeniu zadanie zwraca `WILL_RESUME` i wznawia kopię przy kolejnym uruchomieniu. `0` wykonuje całą kopię w jednym przebiegu. |
| **Uruchamiaj tylko z wiersza poleceń** | `Tak` sprawia, że zadanie pomijają wyzwalacze przeglądarkowe: planowanie z opóźnieniem i webcron. Pole dokłada ta wtyczka, Joomla trzyma jego wartość w kolumnie `cli_exclusive`. |

## Próg świeżości z wtyczki quickicon

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

---

# Dla programisty

## Dlaczego nie nazywa się `akeebabackup`

Komponent Akeeba Backup trzyma listę rozszerzeń „tylko dla wersji Pro"
(`UpgradeModel::PRO_ONLY_EXTENSIONS`) i przy instalacji oraz **każdej aktualizacji**
wersji Core je odinstalowuje. `plg_task_akeebabackup` jest na tej liście, więc
wtyczka o takiej nazwie zniknęłaby razem z zadaniem przy najbliższej aktualizacji.
Stąd nazwa `akeebacron`.

## Budowanie paczki

```
php build.php
```

Skrypt czyta wersję i listę plików wprost z manifestu i zapisuje archiwum
w `dist/`. Do paczki trafia wyłącznie to, co deklaruje manifest — bez `README.md`,
`build.php` i historii gita.

Jeżeli CLI PHP nie ma włączonego rozszerzenia zip:

```
php -d extension_dir="C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/ext" -d extension=php_zip.dll build.php
```

## Wydanie nowej wersji

Serwerem aktualizacji jest plik `updates.xml` na osobnej gałęzi `release`, wskazany
w manifeście przez `<updateservers>`. Joomla czyta go anonimowo z `raw.githubusercontent.com`
i stamtąd pobiera paczkę z wydania, dlatego repozytorium musi pozostać publiczne.

Kolejność kroków:

1. podbij `<version>` i `<creationDate>` w `akeebacron.xml`
2. zbuduj paczkę (`php build.php`) i zacommituj wersję na `main`
3. `gh release create vX.Y.Z dist/plg_task_akeebacron-X.Y.Z.zip`
4. na gałęzi `release` zaktualizuj w `updates.xml` `<version>`, `<downloadurl>`
   i `<sha256>`, licząc sumę z paczki już opublikowanej w wydaniu

Joomla porównuje `<sha256>` z pobranym plikiem, więc rozjazd sumy zatrzymuje
aktualizację z błędem.

## Test z wiersza poleceń

```
php cli/joomla.php scheduler:list
php cli/joomla.php scheduler:run --id=ID_ZADANIA
```

Wtyczka korzysta z wewnętrznych klas Akeeba Backup (`BackupModel`, `Akeeba\Engine\*`),
których autor nie zobowiązał się utrzymywać w niezmienionej postaci. Po większej
aktualizacji Akeeby warto zajrzeć do dziennika zadań.

---

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
