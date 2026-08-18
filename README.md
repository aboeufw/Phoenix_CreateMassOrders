# Phoenix_CreateMassOrders

Module Magento Commerce 2.4.7-P5 pour générer en masse des commandes de test sur un
environnement de **recette**, afin d'alimenter l'ERP avec des données de test.

## Installation

```bash
# Depuis la racine du projet Magento
cp -r app/code/Phoenix vendor-app/code/  # ou copier directement le dossier app/code/Phoenix

bin/magento module:enable Phoenix_CreateMassOrders
bin/magento setup:upgrade
bin/magento cache:flush
```

## Commande

```
bin/magento phoenix:createmassorders:generate \
    --count=10 \
    --customer-erp-id=123456 \
    --sku=SKU-001 --sku=SKU-002 \
    --payment-method=checkmo
```

### Options

| Option               | Raccourci | Obligatoire | Description                                                                 |
|----------------------|-----------|-------------|-------------------------------------------------------------------------------|
| `--count`            | `-c`      | Oui         | Nombre de commandes à générer.                                               |
| `--customer-erp-id`  | `-e`      | Oui         | Numéro client ERP (attribut client `wesco_customer_erp_id`) pour la facturation **et** la livraison. |
| `--sku`              | `-s`      | Oui         | SKU à inclure dans chaque commande (répéter l'option pour plusieurs SKU, quantité 1 par SKU). |
| `--payment-method`   | `-p`      | Non         | Code du mode de paiement (défaut : `checkmo`). En mode `--file`, valeur de repli si aucune ligne de la commande ne renseigne `payment_method`. |
| `--file`             | `-f`      | Non         | Chemin vers un fichier plat décrivant plusieurs commandes, une ligne par référence produit regroupée par `order` (voir [Mode fichier](#mode-fichier)). Rend `--count`, `--customer-erp-id` et `--sku` inutilisés. |

### Comportement

- Le client est recherché via l'attribut client **`wesco_customer_erp_id`** (numéro
  client ERP), et non par email. Il doit avoir une adresse de facturation et une
  adresse de livraison par défaut définies dans son carnet d'adresses.
- Chaque commande générée contient un exemplaire de chaque SKU passé en paramètre.
- Le transporteur utilisé est fixé sur **"Standard"** (`transporter_transporter`).
- Le mode de paiement par défaut est **Paiement par chèque** (`checkmo`), mais un
  autre code de mode de paiement actif peut être fourni via `--payment-method`.
- Les commandes sont créées à l'état **new / pending**, sans facturation ni
  expédition automatique.
- Si un SKU est introuvable, aucune commande n'est créée. Les autres erreurs
  (adresse manquante, échec de validation de commande, etc.) sont journalisées
  par commande sans interrompre la génération des suivantes.
- Les champs custom Wesco `wesco_first_estimated_delivre_date_from` et
  `wesco_first_estimated_delivre_date_to` sont renseignés avec la date du jour
  + 2 jours. Les autres champs custom (`wesco_customer_profile`,
  `wesco_partial`, `wesco_confirme`, `wesco_multiple_shipment`,
  `wesco_is_erp_order`, `wesco_send_to_erp`, `person_in_charge`, etc.) sont
  laissés à leur valeur par défaut de la table.

## Exemple

```bash
bin/magento phoenix:createmassorders:generate -c 50 -e 123456 -s SKU-A -s SKU-B -s SKU-C
```

Génère 50 commandes identiques (mêmes SKU, même client) payées par chèque et
expédiées via le transporteur Standard.

## Mode fichier

Pour générer plusieurs commandes différentes en une seule exécution, utiliser
`--file`. Le fichier contient **une ligne par référence produit** ; les lignes
sont regroupées par la colonne `order`, et **une commande est créée par
identifiant de commande** (le `--count` ne s'applique pas).

```bash
bin/magento phoenix:createmassorders:generate --file=/chemin/vers/commandes.csv
```

Le fichier est au format CSV, colonnes séparées par `;`, avec une ligne d'en-tête :

```csv
order;customer_erp_id;sku;qty;payment_method
SJ15121;AA315267;15141115;2;
SJ15121;AA315267;154515115;1;
SJ15121;AA315267;1514999;1;
SJ15122;AA315557;1544449;1;
```

L'exemple ci-dessus génère **2 commandes** : `SJ15121` avec 3 lignes produit et
`SJ15122` avec 1 ligne produit.

| Colonne            | Obligatoire | Description                                                                                       |
|--------------------|-------------|---------------------------------------------------------------------------------------------------|
| `order`            | Oui         | Identifiant de regroupement. Toutes les lignes partageant cette valeur forment une seule commande. |
| `customer_erp_id`  | Oui         | Numéro client ERP (attribut `wesco_customer_erp_id`). Doit être identique sur toutes les lignes d'une même commande. |
| `sku`              | Oui         | SKU de la référence produit de la ligne.                                                           |
| `qty`              | Non         | Quantité commandée pour ce SKU (défaut : `1`). Le séparateur décimal peut être `.` ou `,`.         |
| `payment_method`   | Non         | Code du mode de paiement. Il suffit de le renseigner sur une seule ligne de la commande ; si aucune ligne ne le renseigne, `--payment-method` est utilisé (donc `checkmo` par défaut). |

Détails de traitement :

- Les lignes d'un même `order` **n'ont pas besoin d'être contiguës** dans le fichier :
  le regroupement se fait sur la valeur de la colonne, l'ordre des commandes créées
  suit la première apparition de chaque identifiant.
- Si un même SKU apparaît plusieurs fois dans une même commande, les quantités sont
  **cumulées** en une seule ligne de commande.
- Les colonnes `qty` et `payment_method` peuvent être absentes de l'en-tête.
- Les lignes vides sont ignorées.
- Si une commande échoue (client ou SKU introuvable, mode de paiement inactif,
  numéros client ERP ou modes de paiement divergents au sein d'un même `order`,
  quantité invalide, etc.), l'erreur est journalisée pour cette commande et les
  commandes suivantes sont traitées normalement.
- En fin d'exécution, la correspondance entre l'identifiant du fichier et le numéro
  de commande Magento généré est affichée (`SJ15121 -> 000000123`).
