[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-8.1%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Check Style](https://github.com/Schnittcher/Sankey/actions/workflows/style.yml/badge.svg)](https://github.com/Schnittcher/Sankey/actions/workflows/style.yml)
[![Tests](https://github.com/Schnittcher/Sankey/actions/workflows/tests.yml/badge.svg)](https://github.com/Schnittcher/Sankey/actions/workflows/tests.yml)

# Sankey
Dieses Modul stellt Energie- oder andere Flüsse als Sankey-Diagramm in der Kachel-Visualisierung von IP-Symcon dar.

## Inhaltsverzeichnis
- [Sankey](#sankey)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [Screenshots](#screenshots)
  - [1. Voraussetzungen](#1-voraussetzungen)
  - [2. Funktionsumfang](#2-funktionsumfang)
  - [3. Enthaltene Module](#3-enthaltene-module)
  - [4. Installation](#4-installation)
  - [5. Konfiguration in IP-Symcon](#5-konfiguration-in-ip-symcon)
  - [6. Spenden](#6-spenden)
  - [7. Lizenz](#7-lizenz)

## Screenshots

![Sankey-Kachel im Live-Modus](docs/screenshots/sankey.png)

## 1. Voraussetzungen

* mindestens IPS Version 8.1
* Für das statische Diagramm: ein Archiv-Control mit protokollierten Variablen
* Die maximierte Ansicht der Kachel steht erst ab IPS 9.1 zur Verfügung

## 2. Funktionsumfang

* Darstellung beliebiger Flüsse zwischen Quellen und Zielen als Sankey-Diagramm
* Live-Ansicht: Das Diagramm aktualisiert sich, sobald sich eine verwendete Variable ändert
* Archiv-Ansicht: Werte eines frei wählbaren Zeitraums aus dem Archiv
* Anpassbare Farben und optionale Zusatzinfo je Fluss

Die einzelnen Funktionen stehen in der README des Moduls.

## 3. Enthaltene Module

* [Sankey](Sankey/README.md)
  * Zeigt das Sankey-Diagramm als Kachel an.
  * Live- und Archiv-Ansicht, umschaltbar in der Kachel.

## 4. Installation
Die Installation erfolgt über den IP-Symcon Module Store.

## 5. Konfiguration in IP-Symcon
Unter "Instanz hinzufügen" das Modul "Sankey" (Hersteller "Kai Schnittcher") anlegen und die Verbindungen konfigurieren. Details stehen in der [Modul-README](Sankey/README.md).

## 6. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 7. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
