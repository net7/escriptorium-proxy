# Come funziona il proxy Laravel per eScriptorium

## Introduzione

Il proxy Laravel svolge il ruolo di intermediario applicativo tra un client e il servizio eScriptorium. Il suo obiettivo non è limitarsi a inoltrare richieste HTTP, ma trasformare un processo di elaborazione documentale composto da molte operazioni differenti in un servizio unitario, osservabile e più semplice da utilizzare.

eScriptorium mette a disposizione gli strumenti necessari per importare documenti, analizzare la struttura delle pagine, riconoscere il testo ed esportare i risultati. Queste attività, tuttavia, non corrispondono a una singola chiamata sincrona. Richiedono la creazione di risorse remote, l'avvio di task Celery, il controllo del loro avanzamento, la produzione differita di un export e il recupero finale del file. Il proxy raccoglie queste operazioni in un unico processo applicativo identificato da un UUID.

Dal punto di vista del client, il funzionamento diventa lineare: si invia un manifest IIIF oppure un insieme di immagini, si riceve l'identificativo del processo, si controlla periodicamente lo stato e, al completamento, si legge o si scarica il risultato. Dietro questa interfaccia essenziale, Laravel coordina eScriptorium tramite REST, polling dei task, WebSocket, database e job asincroni.

Questo documento descrive il funzionamento del sistema seguendo il percorso di una richiesta. Oltre alle operazioni eseguite, chiarisce le ragioni delle principali decisioni architetturali.

## Perché il sistema è una facade applicativa e non un semplice reverse proxy

Un reverse proxy tradizionale riceve una richiesta e la inoltra quasi senza modificarla al servizio di destinazione. In questo caso una soluzione simile non sarebbe sufficiente, perché il contratto nativo di eScriptorium espone direttamente il proprio modello operativo: progetti, documenti, parti, livelli di trascrizione, task asincroni, notifiche ed export. Un client dovrebbe conoscere la sequenza corretta delle chiamate, conservare tutti gli identificativi remoti e interpretare stati appartenenti a componenti diversi.

Laravel introduce invece una facade applicativa stateful. La facade espone un contratto più piccolo e stabile e assume la responsabilità di tradurlo nelle operazioni richieste da eScriptorium. L'aggettivo *stateful* è importante: il proxy non dimentica la richiesta dopo averla accettata, ma ne conserva proprietario, input, identificativi remoti, avanzamento ed esito.

Questa scelta risponde a tre esigenze. La prima è ridurre l'accoppiamento tra client ed eScriptorium: un consumatore dell'API non deve conoscere la struttura interna di Django o Celery. La seconda è rendere durevole un'elaborazione che può durare minuti o ore. La terza è applicare un modello uniforme di autenticazione, ownership e download, indipendentemente dalla modalità con cui eScriptorium esegue il lavoro.

Il proxy non effettua direttamente segmentazione o riconoscimento. Il calcolo OCR/HTR rimane in eScriptorium e nei suoi worker. Laravel si occupa dell'orchestrazione: decide quale operazione avviare, registra il risultato di ogni passaggio e determina quando il processo può avanzare allo step successivo.

## L'ingresso della richiesta

Le API applicative sono esposte sotto il prefisso `/api/v1` e richiedono l'header `X-API-Key`. Il client può interrogare la disponibilità del servizio, ottenere script e modelli, avviare un processo da manifest IIIF o da immagini, leggere lo stato di una trascrizione e scaricarne l'export.

Quando arriva una richiesta, il middleware di autenticazione identifica innanzitutto il soggetto che la sta eseguendo. Questo passaggio non serve soltanto ad autorizzare l'accesso. L'identità individuata determina anche quali credenziali verranno utilizzate verso eScriptorium, a chi apparterrà il processo locale e quale ciclo di vita avranno progetto e documento remoti.

Il proxy supporta due modalità di autenticazione: Service Mode e Direct Mode. Entrambe producono lo stesso contratto pubblico, ma rappresentano due modi diversi di rapportarsi a eScriptorium.

## Perché esistono Service Mode e Direct Mode

La Service Mode è pensata per i client che vogliono utilizzare la capacità di elaborazione senza possedere o gestire un account eScriptorium personale. Il chiamante usa una chiave generata dal proxy, riconoscibile dal prefisso `esk_`. Nel database locale la chiave viene conservata come hash SHA-256, mentre Laravel usa un account di servizio configurato per autenticarsi verso eScriptorium.

Il token dell'account di servizio viene ottenuto tramite l'endpoint di autenticazione Django e mantenuto temporaneamente in cache. Progetto e documento creati con questa identità hanno natura operativa: servono a eseguire il workflow e vengono rimossi dopo che il risultato è stato acquisito dal proxy.

La Direct Mode è rivolta invece a un utente già presente in eScriptorium. In questo caso il valore di `X-API-Key` è un token Django REST Framework. Laravel lo riconosce interrogando la tabella dei token di eScriptorium e associa l'utente remoto a una chiave virtuale locale. Le richieste REST successive vengono eseguite con il token personale del chiamante.

In Direct Mode il progetto e il documento appartengono all'account reale dell'utente e restano disponibili in eScriptorium. L'utente può quindi ritrovarli nell'interfaccia della piattaforma, consultarli o proseguire il lavoro manualmente. Il proxy conserva il token nella trascrizione usando la cifratura Laravel, perché i job eseguiti in un momento successivo devono poter ricostruire la stessa identità.

La presenza delle due modalità evita di imporre un solo modello d'uso. La Service Mode offre un servizio gestito, adatto ad automazioni e integrazioni che desiderano soltanto il risultato. La Direct Mode preserva invece identità e proprietà delle risorse eScriptorium. La facade mantiene uguale l'esperienza del client, pur rispettando due esigenze operative differenti.

## Ownership e isolamento dei processi

Ogni processo locale è collegato alla chiave che lo ha creato. Quando il client chiede lo stato o il download, Laravel cerca contemporaneamente l'UUID della trascrizione e l'identificativo della chiave autenticata. Conoscere l'UUID di un'altra elaborazione non è quindi sufficiente per accedervi.

In Service Mode l'ownership coincide con la singola chiave proxy. In Direct Mode coincide con l'utente eScriptorium: token diversi appartenenti allo stesso utente convergono sulla stessa identità virtuale locale. Questa scelta rende stabile la proprietà delle trascrizioni anche quando l'utente rigenera il proprio token remoto.

L'UUID locale ha inoltre una funzione di disaccoppiamento. Il client non deve utilizzare direttamente il PK del progetto o del documento eScriptorium. Gli identificativi remoti restano dettagli di orchestrazione conservati dal proxy, mentre il contratto esterno usa un identificatore indipendente dal modello dati di Django.

## La preparazione del processo

I due endpoint di avvio ricevono informazioni comuni: script, modello di riconoscimento, eventuale modello di segmentazione, direzione del testo e formato di export. Laravel valida la struttura della richiesta e interroga eScriptorium per tradurre lo `script_id` nel nome di script richiesto durante la creazione del documento.

Se il chiamante non specifica un documento esistente in Direct Mode, il proxy crea un progetto e un documento con nomi generati. Queste risorse costituiscono l'ambiente remoto nel quale verranno importate le pagine, prodotte le segmentazioni e salvate le trascrizioni. Quando viene fornito un `document_id` in Direct Mode, il proxy usa invece il documento accessibile dall'utente e ne ricava le informazioni necessarie, come la direzione di lettura.

La creazione delle risorse iniziali avviene prima dell'invio della pipeline asincrona. In questo modo la richiesta viene accettata con una correlazione già definita tra il processo locale e il workspace remoto. Laravel può salvare nella trascrizione i PK di progetto e documento e consegnare ai job un punto di partenza deterministico.

Il record locale contiene anche l'input normalizzato, il formato richiesto, il nome del futuro livello di trascrizione e lo stato iniziale `PENDING`. Dopo il commit del record viene inviato il primo job. Separare il salvataggio dal dispatch permette ai worker di recuperare sempre un aggregate già persistito.

## Le due sorgenti documentali

Il proxy accetta un manifest IIIF oppure immagini caricate direttamente. I due ingressi convergono nella stessa pipeline, ma la prima fase è diversa.

Nel flusso IIIF il client invia l'URL del manifest e, facoltativamente, un intervallo di pagine. Il proxy non scarica le immagini nel processo web: comunica a eScriptorium l'URI e la modalità di import, lasciando a Django e Celery la responsabilità di interpretare il manifest e creare le parti del documento. Se non viene fornita una selezione, il comportamento predefinito considera le prime dieci pagine. I numeri esposti al client sono 1-based, mentre eScriptorium usa l'ordine 0-based; il proxy esegue questa traduzione quando associa le pagine alle parti remote.

Nel flusso da upload, ogni immagine viene inizialmente salvata nello storage privato Laravel. La request HTTP può quindi concludersi dopo aver reso durevoli i file e il record del processo. Sarà il worker a leggere i temporanei, inviarli uno alla volta come multipart a eScriptorium e rimuoverli dopo il trasferimento.

Questa separazione evita di mantenere aperta la connessione del client durante tutte le chiamate remote. È particolarmente utile con più immagini, perché il caricamento verso eScriptorium può essere ripetuto o proseguito dal sistema di queue senza dipendere dalla durata della richiesta iniziale.

## Perché l'elaborazione è asincrona

Importazione, segmentazione, riconoscimento ed export non hanno tempi compatibili con una normale request-response sincrona. Il loro completamento dipende dalla dimensione del documento, dai modelli utilizzati, dalla disponibilità dei worker Celery e, in alcuni ambienti, dall'accesso a risorse GPU.

Per questo motivo gli endpoint di avvio restituiscono subito una risposta `201` con l'UUID della trascrizione. L'UUID rappresenta una promessa di elaborazione: il lavoro è stato registrato e verrà portato avanti dai worker, mentre il client può chiudere la connessione e tornare successivamente.

Laravel suddivide il workflow in una catena di job. Esistono job per avviare o controllare l'import, recuperare le parti, avviare o controllare la segmentazione, creare il livello di trascrizione, avviare o controllare il riconoscimento, scaricare l'export e processare il file finale.

La scelta di più job, invece di un unico processo monolitico, crea confini espliciti tra le fasi. Ogni job ha una responsabilità precisa, aggiorna lo stato, salva la risposta remota e invia il successore soltanto quando sono soddisfatte le relative precondizioni. In caso di interruzione, il sistema può ritentare la fase interessata senza dover mantenere in memoria l'intero workflow.

I worker Laravel sono processi long-lived. All'inizio di ogni job viene ricostruito il contesto di autenticazione partendo dalla trascrizione: Service Mode usa l'account configurato, Direct Mode decifra il token personale. Questo passaggio è necessario perché il job viene eseguito fuori dalla request originaria e può essere elaborato da un container differente.

## Lo stato pubblico e lo stato tecnico

Il client osserva una macchina a stati sintetica. Un processo attraversa normalmente `PENDING`, `IMPORTING`, `SEGMENTING`, `TRANSCRIBING`, `DOWNLOADING`, `PROCESSING` e `COMPLETED`. Un fallimento permanente conduce a `FAILED`.

Questi stati descrivono il significato funzionale della fase, non ogni singola chiamata interna. Il recupero delle parti e la creazione del livello di trascrizione, per esempio, non introducono uno stato pubblico aggiuntivo. La scelta mantiene semplice il contratto e permette al client di rappresentare l'avanzamento senza conoscere tutti i dettagli di eScriptorium.

Parallelamente, il campo JSON `service_data` conserva lo stato tecnico. Al suo interno sono registrati progetto, documento, parti selezionate, livello di trascrizione e step della pipeline. Ogni step può contenere stato, timestamp, risposta remota, messaggio di errore e informazioni di polling.

La distinzione tra stato pubblico e stato tecnico consente di servire due pubblici diversi. Il client API riceve una vista stabile e comprensibile; operatori e job dispongono invece dei dati necessari per ricostruire ciò che è accaduto e continuare il processo.

## Importazione e selezione delle parti

Nel flusso da manifest, il primo job invia a eScriptorium la richiesta di import IIIF. Django crea un'operazione di import e Celery ne esegue il lavoro. Il proxy avvia quindi una serie di controlli fino a quando il task remoto risulta concluso.

Nel flusso da immagini, il primo job carica i file e il controllo successivo osserva lo stato di conversione delle parti create. Dopo l'import, entrambi i flussi convergono nello stesso punto: il proxy interroga l'elenco delle parti del documento e associa i numeri di pagina richiesti ai rispettivi PK eScriptorium.

L'elenco dei PK viene salvato nella trascrizione e riutilizzato nelle fasi successive. Stabilire una sola volta il perimetro delle parti garantisce che segmentazione, riconoscimento ed export operino sullo stesso insieme di pagine. Il proxy traduce così una selezione comprensibile dal client in una collezione di identificativi nativi di eScriptorium.

## Segmentazione e riconoscimento

La segmentazione analizza la struttura visiva delle pagine. Il proxy invia le parti selezionate, la direzione del testo, l'eventuale modello di segmentazione e l'indicazione di eseguire le operazioni di layout e baseline. eScriptorium distribuisce il calcolo ai worker Celery e registra uno o più task associati al documento.

Quando tutti i task di segmentazione sono terminati, Laravel crea un livello di trascrizione remoto. Un documento eScriptorium può contenere più livelli; crearne uno specifico permette al proxy di identificare il contenitore nel quale verrà scritto il nuovo riconoscimento e che sarà successivamente usato per l'export.

Il job di trascrizione invia quindi modello di riconoscimento, livello e parti. Kraken/eScriptorium produce il testo sulle linee individuate durante la segmentazione. Anche questa fase è asincrona e viene seguita attraverso i task del documento.

Separare segmentazione e riconoscimento riflette il modello di eScriptorium e consente di usare modelli diversi per i due problemi. La prima fase comprende la geometria e l'ordine della pagina; la seconda interpreta il contenuto delle linee. Il proxy conserva questa distinzione internamente, pur presentandola al client come un unico processo di trascrizione.

## Perché importazione, segmentazione e riconoscimento usano il polling

Per queste fasi eScriptorium espone task interrogabili tramite API. Il proxy può quindi determinare lo stato chiedendo periodicamente al servizio quali operazioni del documento siano in coda, in esecuzione, completate o terminate con errore.

Il polling non viene realizzato bloccando un worker con una lunga attesa. Ogni controllo che trova il task ancora attivo invia una nuova istanza dello stesso job con un ritardo configurato, normalmente di trenta secondi. Il worker corrente torna libero e Redis conserva il controllo futuro nella queue.

Questa decisione rende l'attesa persistente e distribuibile. Se un worker viene riciclato, il prossimo controllo rimane nella coda; se sono presenti più worker, ciascuno può elaborare uno degli step disponibili. I contatori e gli orari di polling vengono salvati in `service_data`, così l'avanzamento non dipende dalla memoria di un singolo processo.

Il polling è adatto a queste fasi perché lo stato del task remoto è la fonte autorevole e può essere riletto in qualsiasi momento. L'export presenta invece una caratteristica diversa: il risultato utile non è soltanto uno stato, ma anche il collegamento al file appena prodotto. Per questa ragione il proxy adotta un meccanismo di notifica basato su WebSocket.

## L'avvio dell'export

Dopo il riconoscimento, il job di download prepara l'ultima operazione remota. Rilegge il documento per ottenere i tipi di regione validi e costruisce il payload di export con formato, livello di trascrizione e parti selezionate.

I formati supportati dal contratto sono TEI XML, testo semplice, PAGE XML, ALTO XML e OpenITI mARkdown. Alcuni exporter dipendono dalle funzionalità abilitate nell'istanza eScriptorium. La richiesta REST di export non restituisce immediatamente il file: conferma che l'operazione è stata accettata, mentre Celery genera l'artefatto in un momento successivo.

Questo comportamento introduce la necessità di sapere quando il file è realmente pronto e dove può essere scaricato. È qui che interviene il WebSocket.

## Perché il proxy usa un WebSocket per l'export

L'uso del WebSocket non è una scelta puramente prestazionale e non serve a mantenere una comunicazione permanente con il client pubblico. È una conseguenza del contratto asincrono nativo di eScriptorium.

Quando Laravel invia il comando REST di export, la risposta indica soltanto che il task è stato avviato. Il file viene costruito in background. Al termine, eScriptorium pubblica attraverso Django Channels una notifica destinata all'utente e un evento associato al documento. La notifica può contenere direttamente il link al media prodotto.

Il proxy si collega quindi al WebSocket prima di avviare l'export, entra nella room del documento e solo dopo invia il comando REST. L'ordine è intenzionale: la sottoscrizione deve essere attiva quando Celery completa il task e pubblica l'evento. Dopo il frame di join, il job concede al consumer il tempo necessario per registrare la connessione nella room e poi avvia l'elaborazione.

Usare la notifica nativa permette di sincronizzarsi con il momento effettivo di disponibilità del file. Un'attesa di durata fissa sarebbe poco affidabile, perché il tempo di export varia con dimensione e formato. Un polling continuo del media storage richiederebbe invece di ricostruire ripetutamente un percorso e distinguere tra file non ancora pronto e file assente. Il WebSocket fornisce un segnale esplicito prodotto dallo stesso task che ha generato l'export.

La soluzione è quindi ibrida. REST viene usato per i comandi e per la lettura delle risorse; il WebSocket viene usato per l'evento di completamento che accompagna la produzione del file. Il socket vive soltanto all'interno del job di download: il client del proxy non deve aprire connessioni WebSocket e continua a utilizzare la normale API HTTP.

Questa mediazione è uno dei principali valori della facade. Laravel assorbe la complessità del protocollo misto di eScriptorium e restituisce al chiamante un modello uniforme basato su stato e download.

## Perché il WebSocket richiede una sessione Django

Le API REST di eScriptorium accettano token Django REST Framework. Django Channels, invece, usa `AuthMiddlewareStack` e riconosce l'utente attraverso una sessione Django. Un token valido per le API non viene automaticamente interpretato durante l'handshake WebSocket.

In Service Mode il proxy possiede username e password dell'account di servizio. Può quindi eseguire il normale flusso web: apre la pagina di login, acquisisce il token CSRF, invia il form e ottiene il cookie `sessionid`. Quel cookie viene inserito nell'handshake del WebSocket.

In Direct Mode Laravel dispone del token personale dell'utente, ma non della sua password. Per collegare l'identità REST al meccanismo di sessione di Channels, il proxy costruisce una sessione Django temporanea. Recupera l'utente associato al token, prepara i campi di autenticazione attesi da Django, firma i dati con la stessa `SECRET_KEY` e inserisce la sessione nella tabella `django_session`. Il relativo `sessionid` può quindi autenticare il socket.

La sessione esiste soltanto per il tempo necessario all'export. Nel blocco finale del job il socket viene chiuso e la riga viene eliminata. Questa scelta crea un ponte circoscritto tra due sistemi di autenticazione già presenti: il token REST dimostra l'identità dell'utente, mentre la sessione la rende comprensibile a Channels.

Una volta autenticato, il socket entra nella room del documento. La room restringe le notifiche al contesto che interessa il processo corrente, invece di affidarsi esclusivamente al flusso generale di messaggi dell'utente.

## Download e post-processing

Quando arriva la notifica di completamento, il proxy ricava il percorso del media e scarica il file usando ancora l'identità corrente. Il contenuto viene scritto nello storage privato Laravel sotto una directory dedicata all'UUID della trascrizione.

Il job successivo esegue il post-processing in base al formato. Per il testo semplice legge il file e ne salva il contenuto nel campo `text`. Per PAGE XML, ALTO XML e OpenITI mARkdown conserva lo ZIP come artefatto scaricabile. Per TEI XML estrae gli XML delle singole pagine, li ordina, ne unisce intestazione e corpo e produce un documento TEI unitario nel campo `text`; lo ZIP originale resta disponibile per il download.

La separazione tra download e post-processing permette di distinguere l'acquisizione del file dalla sua interpretazione. Il primo job gestisce WebSocket, autenticazione, rete e storage; il secondo opera su un artefatto ormai locale e applica la logica specifica del formato.

Al termine vengono valorizzati il percorso dell'export e lo stato `COMPLETED`. Il controller può quindi restituire il testo, quando previsto dal formato, e un URL autenticato per scaricare il file.

## Perché il proxy conserva una copia locale dell'export

In Service Mode il progetto eScriptorium è una risorsa temporanea e viene eliminato dopo l'acquisizione del risultato. Se il proxy restituisse semplicemente il link remoto, la disponibilità del download dipenderebbe dalla permanenza di quel progetto e dal media storage di eScriptorium.

La copia locale rende invece l'output parte del dominio del proxy. Il risultato rimane collegato all'UUID e al proprietario della richiesta, può essere servito attraverso l'autenticazione Laravel e non richiede al client di possedere una sessione Django.

Anche in Direct Mode la copia offre un contratto uniforme: indipendentemente dal fatto che le risorse remote restino nell'account dell'utente, il client recupera sempre il risultato dallo stesso endpoint del proxy.

Lo storage è privato e non viene esposto direttamente da Nginx. Il download passa dal controller, che applica nuovamente autenticazione e ownership. Il volume è condiviso tra PHP-FPM e worker perché il file viene prodotto dal processo asincrono ma servito dal processo web.

## Perché MariaDB e PostgreSQL rimangono separati

MariaDB contiene il modello applicativo del proxy: chiavi locali, trascrizioni, stato del workflow, log opzionali e tabelle infrastrutturali Laravel. PostgreSQL contiene invece il dominio nativo di eScriptorium: utenti, token, progetti, documenti, task e sessioni Django.

Mantenere i due database separati conserva i rispettivi confini. Laravel può evolvere il proprio contratto e la propria macchina a stati senza trasformare le tabelle Django in tabelle applicative del proxy. Allo stesso tempo eScriptorium continua a essere responsabile dei dati necessari al calcolo OCR/HTR.

L'accesso diretto di Laravel a PostgreSQL è circoscritto alle funzioni di integrazione che richiedono la conoscenza dell'identità Django: riconoscimento dei token Direct Mode e gestione della sessione temporanea usata da Channels. Le normali operazioni su progetto, documento, parti e trascrizioni passano attraverso le API REST di eScriptorium.

Il record `Transcription` in MariaDB agisce quindi come aggregate locale. Collega l'identità del client, l'input ricevuto, gli identificativi remoti e il risultato. `service_data` svolge la funzione di journal tecnico e permette a ogni job di ricostruire il punto raggiunto dal workflow.

## Il ruolo di Redis e dei worker

Redis fornisce al proxy queue, cache, rate limiting e sessioni. Nello stack integrato è usato anche da eScriptorium come broker e result backend Celery, cache Django e channel layer per i WebSocket.

Il suo ruolo è rendere asincrona la comunicazione tra i componenti. Laravel pubblica i job e i worker li consumano; Django pubblica i task e Celery li esegue; Channels distribuisce gli eventi alle room attive. Redis non contiene il risultato documentale finale, ma coordina il movimento del lavoro tra processi indipendenti.

La cache del token Service Mode evita di ripetere il login REST a ogni chiamata. Il rate limiter associa invece una finestra di utilizzo a ogni chiave del proxy. Queste funzioni condividono la stessa infrastruttura transitoria ma rimangono separate tramite configurazioni e namespace propri dei framework.

I container web e worker condividono codice, configurazione Laravel e storage, ma hanno compiti differenti. PHP-FPM gestisce le richieste brevi del client; il queue worker esegue le operazioni lunghe e remote. Sul lato eScriptorium, Django espone API e pagine, Daphne gestisce Channels e i worker Celery svolgono il calcolo.

## Retry, errori e osservabilità

Le chiamate operative della pipeline usano i retry della queue Laravel. Se un'operazione genera un'eccezione, il job può essere ripetuto applicando il proprio backoff. I job di polling adottano un comportamento diverso: quando il task remoto è ancora in corso, pianificano un nuovo controllo senza trattare l'attesa come un errore.

Ogni job aggiorna sia lo stato pubblico sia lo step tecnico. Al termine permanente con errore, la trascrizione passa a `FAILED`, il messaggio viene conservato in `service_data`, Laravel registra il job in `failed_jobs` e i log includono UUID e contesto remoto.

Questa stratificazione permette di osservare il processo da più punti. Il client vede uno stato sintetico; gli operatori possono consultare step, tentativi e risposte in MariaDB; i task eScriptorium restano visibili tramite le API, i report Django e Flower; i log collegano le operazioni all'UUID locale.

La persistenza dello stato è ciò che rende recuperabile il workflow. Nessuna fase dipende esclusivamente da variabili in memoria o dalla connessione HTTP originaria. Queue, database e identificativi remoti costituiscono insieme la continuità del processo.

## L'esperienza del client

Per chi usa il proxy, l'intera architettura si riduce a un protocollo semplice. Il client consulta script e modelli, sceglie la sorgente documentale e invia il `POST` di avvio. La risposta contiene l'UUID. Da quel momento il client interroga lo stato con un `GET` fino a `COMPLETED` oppure `FAILED`.

Quando il formato produce testo direttamente fruibile, il campo `text` contiene il risultato elaborato. Quando è disponibile un artefatto, la risposta include l'URL di download. Il download usa la stessa chiave API e lo stesso controllo di ownership del resto del servizio.

Il client non deve creare progetti, interpretare task Celery, aprire WebSocket, gestire cookie Django, calcolare path media o conoscere il ciclo di vita delle risorse remote. Tutte queste responsabilità vengono assorbite dal proxy.

## Coerenza delle decisioni architetturali

Le decisioni principali si sostengono reciprocamente. La facade stateful rende possibile nascondere il workflow remoto; la persistenza locale permette di spostare il lavoro fuori dalla request; la queue rende durevole l'esecuzione; il polling segue le fasi per cui eScriptorium espone uno stato interrogabile; il WebSocket intercetta il momento e il link dell'export; lo storage locale conserva il risultato dopo la conclusione del workspace remoto.

Anche la doppia autenticazione segue la stessa logica. Il proxy può offrire un servizio gestito con risorse temporanee oppure agire per conto dell'utente eScriptorium, mantenendo però identico il contratto pubblico. MariaDB rappresenta il processo visto dal proxy, PostgreSQL rappresenta il dominio eScriptorium e `Transcription` mantiene la correlazione tra i due.

Il sistema realizza così una saga applicativa: ogni fase produce uno stato persistente e abilita la successiva, mentre Laravel coordina servizi che rimangono autonomi. Il risultato è un'API che presenta come un'unica elaborazione ciò che internamente è una collaborazione tra HTTP, queue, database, Celery, Channels e storage.

## Conclusione

Il proxy Laravel trasforma eScriptorium da piattaforma articolata in un servizio di elaborazione documentale utilizzabile attraverso poche operazioni coerenti. Non sostituisce i componenti di eScriptorium, ma li compone: usa Django per le risorse e l'autenticazione, Celery per il lavoro intensivo, Channels per la notifica dell'export e lo storage locale per consegnare un risultato durevole.

La scelta del WebSocket esprime bene la funzione dell'intero proxy. eScriptorium comunica il completamento dell'export attraverso un evento autenticato; Laravel partecipa temporaneamente a quel protocollo, ne acquisisce il risultato e lo traduce in un normale stato HTTP e in un download protetto. La complessità rimane all'interno dell'orchestratore, mentre il client riceve un'interfaccia semplice, stabile e indipendente dai dettagli di implementazione.

Per la descrizione puntuale di componenti, endpoint, payload, job, configurazione e modello dati si rimanda a [Architettura e funzionamento del proxy Laravel per eScriptorium](./ARCHITETTURA_PROXY_ESCRIPTORIUM.md).
