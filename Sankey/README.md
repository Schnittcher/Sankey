# Sankey
   Dieses Modul bietet die Möglichkeit ein Sankey Diagramm in Symcon darzustellen.
 
   ## Inhaltverzeichnis
- [Sankey](#sankey)
  - [Inhaltverzeichnis](#inhaltverzeichnis)
    - [1. Funktionsumfang](#1-funktionsumfang)
    - [2. Vorraussetzungen](#2-vorraussetzungen)
    - [3. Einrichten der Instanzen in IP-Symcon](#3-einrichten-der-instanzen-in-ip-symcon)
    - [4. Statusvariablen](#4-statusvariablen)
      - [Statusvariablen](#statusvariablen)
    - [5. PHP-Befehlsreferenz](#5-php-befehlsreferenz)
  - [6. Spenden](#6-spenden)
  - [7. Lizenz](#7-lizenz)
   
### 1. Funktionsumfang

* Anlegen von Verbindungen
* Anpassung des Diagramm Titels
* Anpassung der Farben
* Sobald eine Variable geändert wird, die sich im Diagramm befindet, wird das Diagramm aktualisiert
### 2. Vorraussetzungen

- IP-Symcon ab Version 8.0

### 3. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' ist das 'Sankey'-Modul unter dem Hersteller 'Kai Schnittcher' aufgeführt.

__Konfigurationsseite__:

Name     | Beschreibung
-------- | ------------------
Titel des Diagramms | Hier kann ein Titel für das DIagramm festgelegt werden.
Höhe | Hier kann die Hähe des Diagramms eingestellt werden.
Titel Farbe | Hier kann die Farbe des Titels eingestellt werden.
Label Farbe | Hier kann die Farbe der Labels im Diagramm  eingestellt werden.
Verbindungen | Hier wird das Diagramm konfiguriert

### 4. Statusvariablen

Die Statusvariablen/Kategorien werden automatisch angelegt. Das Löschen einzelner kann zu Fehlfunktionen führen.

#### Statusvariablen

Name   | Typ     | Beschreibung
------ | ------- | ------------
Sankey-DIagramm|String| IN dieser Variable wird das DIagramm abgelegt.

### 5. PHP-Befehlsreferenz

**SD_UpdateDiagram(integer $InstanceID)** \
Mit dieser Funktion kann das Diagramm neu erstellt werden.

## 6. Spenden

Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:    

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EK4JRP87XLSHW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a> <a href="https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share" target="_blank">Amazon Wunschzettel</a>

## 7. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)