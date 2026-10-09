# Chemins : montrer les boucles sans explosion combinatoire

Opened: 2026-10-09
Closed: 2026-10-09
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

## Fait (2026-10-09, agent bpmn)

- `PathFinder::cover($process)` : des chemins construits un par un ; à chaque choix, un flux pas encore pris, sinon vers le flux non pris le plus proche, sinon la fin la plus proche ; une boucle bouclée, le chemin file vers une fin. Arrêt quand un chemin n'apporte plus rien. Tous complets, tous les flux couverts (les entrées d'une jointure parallèle comptent toutes).
- Nommés par ce qu'ils ont choisi en premier, les boucles d'abord (`↺`), trois au plus puis `…` ; le premier par ses fins (`main: paid`). `Path` prend un label, que `describe()` rend.
- `public_expenditure` : 9 chemins, les 52 flux, chaque boucle visible (au lieu de 3, ou de 200+ avec `maxVisits: 2`).
- symfony-bpmn : `BpmnScenarioService::getCoveringPaths()`, utilisé par `BpmnViewService::paths()` (forme de l'API inchangée) ; `bpmn:paths` montre la couverture, `--all` toutes les combinaisons.
- symfony-testing : les scénarios jouent ces chemins ; le `when` d'une tâche se lit à chaque passage, une tâche rejouée sur une boucle peut décider autrement.

Pas fait : `BpmnScenarioService::play()` prend ses variables par nœud, donc une seule décision par passerelle — un chemin qui boucle n'y est pas jouable tel quel.

