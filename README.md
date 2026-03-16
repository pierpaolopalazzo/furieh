# furiè UniFG <img src="www/logo_unifg.png" alt="Logo UniFG" width="50"> - Signal Processing Toolkit

*Progetto didattico per l'elaborazione di segnali in banda audio*

---

## Descrizione

`furiè UniFG` è un toolkit didattico basato su:

- formato testuale `SRAW-1`
- tool Python per conversione, trasformate e operazioni sui segnali
- launcher web PHP
- viewer e designer HTML/JavaScript

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

- `conf/sraw.conf` contiene le costanti primitive del formato
- `python_tools/` contiene i tool CLI Python
- `www/index.php` è il launcher principale
- `www/viewer/` contiene il visualizzatore
- `www/designer/` contiene il disegnatore di segnali
- `www/data/` è il deposito condiviso di file `.sraw` e `.mp3`

---

## Stato attuale

Il repository contiene già un ambiente funzionante per:

- conversione `MP3 -> SRAW`
- conversione `SRAW -> MP3`
- `FFT / DFT / IFFT / IDFT`
- convoluzione, correlazione, cross-correlazione
- operazioni elementari sui file SRAW
- visualizzazione grafica nel browser
- generazione guidata di segnali di test
- gestione file e cartelle nell’area dati condivisa

---

## Formato SRAW-1

`SRAW-1` è un formato testuale a tripla colonna:

- ascissa intera
- parte reale intera
- parte immaginaria intera

Il file non dichiara da solo se rappresenti un segnale nel dominio del tempo oppure della frequenza: questa interpretazione viene scelta dal tool che lo legge o dal viewer.

Struttura:

```text
SRAW-1
axis_mode,<positive|symmetric>
data
<x_index>,<real_int>,<imag_int>
...
```

---

## Specifiche correnti del formato

Le costanti primitive correnti sono definite in:

```text
conf/sraw.conf
```

Valori correnti:

| Parametro                | Valore      |
| ------------------------ | -----------:|
| `MAX_SAMPLES`            | 2.000.000   |
| `TIME_RESOLUTION_S`      | 0.0000125 s |
| `FREQ_RESOLUTION_HZ`     | 0.01 Hz     |
| `AMP_TIME_RESOLUTION_V`  | 0.00001 V   |
| `AMP_FREQ_RESOLUTION_VS` | 0.00001 Vs  |

Interpretazione pratica:

| Grandezza                         | Significato            |
| --------------------------------- | ---------------------- |
| Risoluzione temporale             | 12,5 µs per indice     |
| Risoluzione in frequenza          | 10 mHz per indice      |
| Risoluzione ampiezza nel tempo    | 10 µV per step intero  |
| Risoluzione ampiezza in frequenza | 10 µVs per step intero |

Con tali specifiche si ottiene un regime molto comodo per la didattica e per la banda audio, ma non l’autodualità perfetta.

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

### rect(t)

Porta rettangolare centrata, durata 1 s, altezza 1 V:

```text
SRAW-1
axis_mode,symmetric
data
-1000000,0,0
-40001,0,0
-40000,100000,0
40000,100000,0
40001,0,0
1000000,0,0
```

### tri(t)

Porta triangolare centrata, durata 1 s, altezza 1 V:

```text
SRAW-1
axis_mode,symmetric
data
-1000000,0,0
-40000,0,0
0,100000,0
40000,0,0
1000000,0,0
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

`ffmpeg` può essere installato nel sistema oppure usato in modalità portable, purché il relativo percorso sia configurato in `conf/sraw.conf`.

---

## Configurazione

Aprire:

```text
conf/sraw.conf
```

e verificare almeno:

- `FFMPEG_PATH`
- parametri primitivi del formato SRAW
- eventuali parametri di export audio

Esempio:

```ini
FFMPEG_PATH=C:\Users\ppp\Desktop\ffmpeg\ffmpeg.exe
MP3_EXPORT_SAMPLE_RATE_HZ=44100
```

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

Più `ffmpeg` disponibile e correttamente configurato.

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
- operazioni SRAW
- refresh dinamico delle liste file
- gestione file e cartelle nell’area dati

---

### File manager integrato

Il file manager opera dentro `www/data/` e consente:

- creazione cartelle
- rinomina file e cartelle
- spostamento file e cartelle
- cancellazione
- gestione dei file `.sraw` e `.mp3`

---

### Viewer `www/viewer/index.html`

Funzioni principali:

- apertura file `.sraw`
- visualizzazione di:
  - parte reale
  - parte immaginaria
  - modulo
  - fase
- switch dominio:
  - tempo
  - frequenza
- zoom e pan

---

### Designer `www/designer/index.html`

Il designer consente generazione e salvataggio di segnali di test.

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

1. ottimizzazioni
   
   1. eventuale esternalizzazione in `sraw.conf` delle definizioni del Designer
   2. eventuale esternalizzazione in `sraw.conf` di parte delle operazioni di `sraw_ops.py`
   3. ulteriore modularizzazione di `index.php`

2. ambienti multiutente didattici
   
   1. gestione di **coorti**
   2. **autenticazione**
   3. **modalità amministratore**
   4. separazione degli ambienti di lavoro per singolo studente
   5. eventuale distinzione tra spazio personale, spazio di coorte e spazio docente

3. miglioramenti documentali e metodologici
   
   1. chiarimento uniforme della semantica SRAW
   2. esplicitazione migliore del regime teorico di autodualità perfetta
   3. documentazione più precisa dei workflow didattici

---

## Licenza

Questo progetto è distribuito sotto licenza **GPL-3.0-or-later**.

Scelta adatta a un contesto didattico e software, perché consente uso, studio e modifica del codice, mantenendo aperte anche le versioni derivate distribuite.

---

## Nota finale

Le costanti definite in `conf/sraw.conf` fanno parte della semantica del formato.  
Cambiarle senza rigenerare i file `.sraw` esistenti può compromettere la coerenza interna del progetto.
