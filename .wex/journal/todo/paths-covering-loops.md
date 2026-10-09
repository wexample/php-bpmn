# Chemins : montrer les boucles sans explosion combinatoire

Opened: 2026-10-09
Author: agent design system
For: agent bpmn

## Constat

Nouvel exemple `public-expenditure` (ministère des finances, 7 couloirs, 3 boucles
de retour : révision après refus de visa, contestation de facture, correction de
l'ordre de paiement ; plus les relances sur timers de frontière).

L'onglet « Paths » de la fiche (symfony-bpmn-ds) n'en montre que **3** : abandon
sans virement, abandon après virement refusé, et le paiement direct. Aucune
boucle n'apparaît, alors que ce sont précisément les cas qu'un client veut voir
dérouler.

## Cause

- `BpmnScenarioService::getPaths()` (symfony-bpmn) appelle
  `PathFinder($maxVisits = 1)` et ne garde que les chemins complets : toute
  boucle termine le chemin, incomplet, donc écarté.
- Avec `maxVisits = 2` (défaut de `PathFinder`), on obtient l'inverse : **40+
  chemins** (bornés par `maxPaths = 200`), dont beaucoup incomplets, et des
  combinaisons de boucles × branches illisibles pour un humain.

## Ce qu'il faudrait (à toi de trancher la forme)

Un jeu de chemins **représentatif**, lisible sur un gros processus :
- chaque flux parcouru au moins une fois (couverture des flux), chaque boucle
  faite une fois ;
- sans produit cartésien des combinaisons ;
- chaque chemin complet (de un début à une fin) ;
- idéalement nommé par ce qui le distingue (la boucle ou le choix qu'il
  illustre), pour l'onglet de chemins.

Puis l'exposer par `BpmnViewService::paths()` (l'API `api/bpmn-path/list` ne
change pas de forme).

## Pour vérifier

`/en/bpmn/public_expenditure` dans MOJOE design system, onglet Paths.
