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
| `--payment-method`   | `-p`      | Non         | Code du mode de paiement (défaut : `checkmo`).                              |

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
