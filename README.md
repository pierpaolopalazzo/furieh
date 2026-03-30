# furiè UniFG <img src="www/logo_unifg.png" alt="Logo UniFG" width="50"> - Signal Processing Toolkit

*Progetto didattico per l'elaborazione di segnali in banda audio*

---

## Descrizione

`furiè UniFG` è un toolkit didattico basato su:

- formato testuale `SRAW-1` / `SRAW-1.1`
- tool Python per conversione, trasformate e operazioni sui segnali
- launcher web PHP con file manager interattivo
- viewer e designer HTML/JavaScript con popup coordinate

Il progetto è pensato per studio, sperimentazione, visualizzazione e manipolazione di segnali nel dominio del tempo e della frequenza.

---

## Struttura del progetto

```text
fourier_unifg/
├── conf/           ← configurazione del formato SRAW e parametri globali
├── python_tools/   ← conversioni, trasformate, convoluzioni, operazioni SRAW
└── www/            ← launcher PHP, viewer, designer, area dati condivisa
```

In particolare:

- `conf/sraw.conf` contiene `MAX_SAMPLES` (unico parametro globale residuo)
- `python_tools/` contiene i tool CLI Python
- `www/index.php` è il launcher principale con file manager interattivo
- `www/viewer/` contiene il visualizzatore con popup coordinate
- `www/designer/` contiene il disegnatore di segnali con popup coordinate
- `www/shared/sraw_shared.js` libreria JS condivisa tra viewer e designer
- `www/data/` è il deposito condiviso di file `.sraw` e `.mp3`

---

## Stato attuale

Il repository contiene già un ambiente funzionante per:

- conversione `MP3 -> SRAW`
- conversione `SRAW -> MP3`
- `FFT / DFT / IFFT / IDFT`
- convoluzione, correlazione, cross-correlazione
- operazioni elementari sui file SRAW (somma, prodotto, gain, shift, specchio, coniugazione, dilatazione)
- visualizzazione grafica nel browser con popup coordinate e dot
- generazione guidata di segnali di test
- file manager interattivo DHTML/JavaScript con navigazione cartelle, viewer/designer integrati

---

## Formato SRAW

Il formato SRAW è un formato testuale a tripla colonna (ascissa intera, parte reale intera, parte immaginaria intera). Il file non dichiara da solo se rappresenti un segnale nel dominio del tempo oppure della frequenza.

### SRAW-1 (legacy)

Struttura minimale senza costanti incorporate. Le risoluzioni venivano lette da `sraw.conf`:

```text
SRAW-1
axis_mode,<positive|symmetric>
data
<x_index>,<real_int>,<imag_int>
...
```

Risoluzione ampiezza: 10 µV (int32, range ±500.000 = ±5 V).

### SRAW-1.1 (corrente)

Il file incorpora le proprie costanti di risoluzione nell’header, prima della sezione `data`:

```text
SRAW-1
axis_mode,<positive|symmetric>
time_res,0.0000125
freq_res,0.01
amp_time_res,0.000000001
amp_freq_res,0.000000001
data
<x_index>,<real_int>,<imag_int>
...
```

Risoluzione ampiezza: **1 nV** (int64, **nessun limite di range verticale**).

Il riconoscimento è automatico: se le direttive `time_res`, `freq_res`, `amp_time_res`, `amp_freq_res` sono presenti nell’header, il file è 1.1; altrimenti è trattato come legacy 1.0 con i vecchi valori (10 µV, ±5 V).

L’estensione `.sraw` resta invariata.

### Specifiche correnti

| Parametro          | SRAW-1 (legacy) | SRAW-1.1 (corrente) |
| ------------------ | ---------------: | -------------------: |
| `time_res`         |     0.0000125 s  |        0.0000125 s   |
| `freq_res`         |         0.01 Hz  |            0.01 Hz   |
| `amp_time_res`     |      0.00001 V   |     0.000000001 V    |
| `amp_freq_res`     |      0.00001 Vs  |     0.000000001 Vs   |
| Range ampiezza     |         ±5 V     |        illimitato    |
| Tipo intero        |         int32    |            int64     |

`MAX_SAMPLES = 2.000.000` resta definito in `conf/sraw.conf` (unico parametro globale).

---

## Semantica del formato

### 1. Campioni sparsi

I file `.sraw` possono contenere soltanto i knot significativi e non necessariamente tutti i campioni della griglia.

### 2. Interpolazione

Tra due knot espliciti il valore viene interpretato con interpolazione lineare.

### 3. Dominio

Il file non incorpora il dominio fisico. Lo stesso file può essere interpretato come:

- tempo
- frequenza

a seconda del contesto operativo.

### 4. Valori interi

Ascisse, parte reale e parte immaginaria sono memorizzate come interi testuali.

---

## Specifiche teoriche per autodualità perfetta

Se si vuole mantenere una vera autodualità pratica, con `FFT` e `IFFT` praticamente identiche salvo l’inversione del segno delle ascisse, il regime di lavoro da assumere è il seguente:

- **100 milioni di campioni**
- **10.000 secondi** di finestra totale
- **campionamento temporale:** `100 µs`
- **campionamento in frequenza:** `100 µHz`
- **banda osservabile:** circa `-5 kHz ... +5 kHz`, cioè `0 ... 10 kHz`

Relazioni:

- `N = 100.000.000`
- `dt = 100 µs = 1e-4 s`
- `T = N * dt = 10.000 s`
- `df = 1 / T = 0,0001 Hz = 100 µHz`
- `fs = 1 / dt = 10 kHz`
- banda di Nyquist: `±5 kHz`

---

## Esempi SRAW

### rect(t) — SRAW-1.1

Porta rettangolare centrata, durata 1 s, altezza 1 V:

```text
SRAW-1
axis_mode,symmetric
time_res,0.0000125
freq_res,0.01
amp_time_res,0.000000001
amp_freq_res,0.000000001
data
-40001,0,0
-40000,1000000000,0
40000,1000000000,0
40001,0,0
```

### tri(t) — SRAW-1.1

Porta triangolare centrata, durata 1 s, altezza 1 V:

```text
SRAW-1
axis_mode,symmetric
time_res,0.0000125
freq_res,0.01
amp_time_res,0.000000001
amp_freq_res,0.000000001
data
-40000,0,0
0,1000000000,0
40000,0,0
```

---

## Installazione

Servono:

- PHP 8.2+
- Python 3
- `numpy`
- `ffmpeg`

Installazione minima lato Python:

```bash
pip install numpy
```

`ffmpeg` può essere installato nel sistema oppure usato in modalità portable. Il percorso può essere configurato dall'interfaccia web (sezione Setup MP3) oppure passato via CLI con `--ffmpeg-path`.

---

## Configurazione

### Formato SRAW

`conf/sraw.conf` contiene solo `MAX_SAMPLES` (numero massimo di campioni per file). Le costanti di risoluzione (tempo, frequenza, ampiezza) sono incorporate in ciascun file `.sraw` (SRAW-1.1). I file legacy senza costanti incorporate vengono letti con i vecchi valori SRAW-1.

### ffmpeg e sample rate MP3

Il percorso di `ffmpeg` e il sample rate di export MP3 si configurano dall'interfaccia web (sezione Setup MP3). I valori vengono salvati come cookie nel browser e passati automaticamente ai tool Python.

Da CLI i tool accettano anche argomenti diretti (`--ffmpeg-path`, `--sample-rate`) che hanno priorità su qualsiasi altro default.

---

## Note operative lato PHP

Il software funziona tramite un server web con PHP. In ambiente Windows può essere usato comodamente anche con sistemi portable come Uniform Server.

È consigliato impostare in PHP valori generosi per:

- memoria disponibile
- timeout di esecuzione
- dimensione dei file in upload
- dimensione massima dei POST

Indicativamente:

- `memory_limit` almeno 512 MB
- timeout esteso per operazioni lunghe
- `upload_max_filesize` adeguato
- `post_max_size` adeguato

---

## Tool Python

### Dipendenze

```bash
pip install numpy
```

Più `ffmpeg` disponibile nel PATH o configurato dall'interfaccia web.

---

### mp3_to_sraw.py

Conversione da MP3 a SRAW nel dominio del tempo.

```bash
python mp3_to_sraw.py input.mp3 output.sraw --channel MIX -v
```

Argomenti principali:

- `--channel L`
- `--channel R`
- `--channel MIX`
- `--ffmpeg-path /path/to/ffmpeg`

---

### sraw_to_mp3.py

Conversione da SRAW a MP3.

```bash
python sraw_to_mp3.py input.sraw output.mp3 --bitrate 128k --part real -v
```

Argomenti principali:

- `--bitrate 128k`
- `--part real`
- `--part imag`
- `--part modulus`
- `--sample-rate 44100`
- `--ffmpeg-path /path/to/ffmpeg`

Nota: il tool è pensato per segnali nel dominio del tempo e tempo positivo.

---

### transformer.py

Trasformate dirette e inverse.

```bash
python transformer.py input.sraw output.sraw --mode fft  -v -b
python transformer.py input.sraw output.sraw --mode dft  -v -b
python transformer.py input.sraw output.sraw --mode ifft -v -b
python transformer.py input.sraw output.sraw --mode idft -v -b
```

Modalità:

- `fft`
- `dft`
- `ifft`
- `idft`

Opzioni utili:

- `-v` / `--verbose`
- `-b` / `--benchmark`

`DFT` e `IDFT` sono modalità didattiche e possono essere molto lente.

---

### convolver.py

Convoluzione e correlazioni.

```bash
python convolver.py a.sraw b.sraw output.sraw --mode conv  --domain time -v -b
python convolver.py a.sraw b.sraw output.sraw --mode corr  --domain time -v -b
python convolver.py a.sraw b.sraw output.sraw --mode xcorr --domain freq -v -b
```

Modalità:

- `conv`
- `corr`
- `xcorr`

Dominio selezionabile:

- `time`
- `freq`

---

### sraw_ops.py

Operazioni elementari sui segnali SRAW.

#### Somma

```bash
python sraw_ops.py a.sraw out.sraw --op sum --input-b b.sraw -v -b
```

#### Prodotto campione per campione

```bash
python sraw_ops.py a.sraw out.sraw --op mul --input-b b.sraw -v -b
```

#### Gain

```bash
python sraw_ops.py a.sraw out.sraw --op gain --gain 2.0 -v -b
```

#### Shift su asse X

```bash
python sraw_ops.py a.sraw out.sraw --op shift --shift-value 100 --shift-unit samples -v -b
python sraw_ops.py a.sraw out.sraw --op shift --shift-value 0.5 --shift-unit time -v -b
python sraw_ops.py a.sraw out.sraw --op shift --shift-value 200 --shift-unit freq -v -b
```

Unità possibili:

- `samples`
- `time`
- `freq`

#### Ribaltamento asse X

```bash
python sraw_ops.py a.sraw out.sraw --op mirror_y -v -b
```

#### Coniugazione

```bash
python sraw_ops.py a.sraw out.sraw --op conj -v -b
```

#### Dilatazione asse X

```bash
python sraw_ops.py a.sraw out.sraw --op dilate_x --dilate-factor 2.0 -v -b
```

---

## Interfaccia web

### Launcher `www/index.php`

Il launcher PHP è il punto centrale del progetto.

Funzioni attuali:

- conversione MP3/SRAW
- trasformate
- convoluzione e correlazioni
- operazioni SRAW (somma, prodotto, gain, shift, specchio, coniugazione, dilatazione)
- file manager interattivo con navigazione cartelle, icone, rinomina, sposta, elimina
- apertura diretta di file nel viewer e designer dal file manager
- configurazione ffmpeg e sample rate MP3 con persistenza cookie

---

### File manager integrato

Il file manager è un componente DHTML/JavaScript interattivo che opera dentro `www/data/`:

- navigazione cartelle con click singolo
- icone distinte per cartelle, file `.sraw` e file `.mp3`
- creazione cartelle, rinomina, spostamento, cancellazione
- pulsanti di apertura diretta nel viewer e nel designer per i file `.sraw`
- comunicazione con il backend PHP via API JSON (fetch)

---

### Viewer `www/viewer/index.html`

Funzioni principali:

- apertura file `.sraw` (da browser integrato o via parametro `?file=`)
- visualizzazione di parte reale, immaginaria, modulo, fase
- switch dominio tempo / frequenza
- zoom e pan con mouse
- popup coordinate con dot sul punto del segnale (segue il mouse)
- riconoscimento automatico SRAW-1 / SRAW-1.1

---

### Designer `www/designer/index.html`

Il designer consente generazione e salvataggio di segnali di test. Supporta popup coordinate con dot, apertura via `?file=`, e salva in formato SRAW-1.1.

Forme attualmente disponibili:

- `rect`
- `tri`
- `sinc`
- `sin`
- `cos`
- `square`
- `gauss`
- `exp`
- `ramp`
- `dirac`
- `step`

Sono inoltre previste operazioni rapide applicabili in fase di progettazione.

---

## Workflow didattico tipico

Esempio d’uso consigliato:

1. generare un segnale nel Designer
2. salvarlo in `www/data/`
3. aprirlo nel Viewer
4. eseguire una FFT dal launcher
5. confrontare segnale e spettro
6. applicare eventuali operazioni SRAW
7. esportare il risultato in MP3 quando utile

---

## Limiti attuali

Il progetto è pensato come toolkit didattico e locale, non come piattaforma server multiutente completa.

Limiti noti:

- uso di `exec()` lato PHP
- dipendenza da configurazione locale di Python e `ffmpeg`
- possibili tempi elevati su file grandi
- `DFT/IDFT` naïve molto lente
- configurazione attuale ottimizzata per praticità didattica, non per autodualità perfetta operativa

---

## Evoluzioni in corso in ordine di priorità

1. ambienti multiutente didattici

   1. gestione di **coorti**
   2. **autenticazione**
   3. **modalità amministratore**
   4. separazione degli ambienti di lavoro per singolo studente
   5. eventuale distinzione tra spazio personale, spazio di coorte e spazio docente

2. miglioramenti documentali e metodologici

   1. esplicitazione migliore del regime teorico di autodualità perfetta
   2. documentazione più precisa dei workflow didattici

---

## Nota sui segnali periodici e sulle “delta” spettrali

Quando si trasforma un segnale periodico ideale, lo spettro teorico non è una curva continua, ma un insieme di righe spettrali, spesso rappresentate come impulsi di Dirac, cioè “delta”.

Questo significa che, nel modello teorico continuo, una sinusoide pura o un’onda periodica non producono un picco largo in frequenza, ma una o più righe concentrate in frequenze esatte.

### Cosa succede nella pratica numerica

Nel software non si osserva mai il segnale per un tempo infinito, ma solo per una durata finita `T_span`.

Per questo motivo, la “delta” ideale non appare come una delta matematica, bensì come un picco finito, la cui altezza dipende anche dalla durata dell’osservazione.

In prima approssimazione:

- più grande è `T_span`
- più stretto diventa il picco in frequenza
- più alta diventa la sua sommità

Quindi il valore letto nel bin della `FFT` non coincide direttamente con il coefficiente teorico della serie di Fourier o con il peso della delta ideale.

### Conseguenza importante

Se una riga spettrale teorica ha coefficiente complesso `c_n`, nella trasformata numerica su finestra finita compare un picco che scala come:

```text
picco_FFT ≈ c_n * T_span
```

oppure, in forma inversa:

```text
c_n ≈ picco_FFT / T_span
```

Questo spiega perché, per segnali periodici, il valore letto nella `FFT` può risultare molto più grande del coefficiente teorico atteso: non è un errore, ma l’effetto naturale della finestra temporale finita.

### Esempio tipico

Per un’onda quadra simmetrica tra `+A` e `-A`, la prima armonica complessa ha modulo teorico:

```text
|c_1| = 2A / π
```

Se però il segnale viene osservato per `T_span = 25 s`, allora il picco numerico corrispondente nella trasformata risulta circa:

```text
picco_FFT ≈ (2A / π) * 25
```

Per `A = 0.1 V`:

```text
|c_1| = 0.2 / π ≈ 0.06366
picco_FFT ≈ 0.06366 * 25 ≈ 1.59
```

Dunque un picco letto attorno a `1.6` è coerente con il coefficiente teorico, purché si tenga conto del fattore `T_span`.

### In sintesi

Per i segnali periodici conviene distinguere sempre tra:

- coefficiente teorico della riga spettrale
- altezza numerica del picco nella FFT
- ampiezza della corrispondente sinusoide nel dominio del tempo

Sono tre quantità collegate, ma non uguali.

Nel toolkit, quando si interpretano gli spettri di segnali periodici, occorre quindi ricordare che le “delta” teoriche vengono visualizzate numericamente come picchi finiti dipendenti dalla finestra di osservazione.

---

## Licenza

Questo progetto è distribuito sotto licenza **GPL-3.0-or-later**.
