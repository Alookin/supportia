<?php

namespace Database\Seeders;

use App\Models\GlpiCategoryMap;
use App\Models\Organization;
use Illuminate\Database\Seeder;

/**
 * Catégories réelles Via-Mobilis, extraites de l'export GLPI (9839 tickets).
 *
 * is_visible_to_users=true  → catégories affichées aux commerciaux
 * is_visible_to_users=false → catégories techniques réservées à l'IA
 *
 * glpi_category_id : IDs réels récupérés via GET /apirest.php/ITILCategory
 * (mis à jour le 2026-04-04, GLPI Via-Mobilis — 27 catégories, Content-Range: 0-26/27)
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(
            ['slug' => 'via-mobilis'],
            [
                'name'            => 'Via-Mobilis (Stockway)',
                'glpi_api_url'    => 'https://glpi.via-mobilis.com/apirest.php',
                'glpi_app_token'  => 'PLACEHOLDER',
                'glpi_user_token' => 'PLACEHOLDER',
                'is_active'       => true,
            ]
        );

        $categories = [

            // ═══════════════════════════════════════════════════════════════
            // VISIBLES AUX COMMERCIAUX (is_visible_to_users=true) — 10 max
            // ═══════════════════════════════════════════════════════════════

            [
                'glpi_category_id'    => 18,
                'slug'                => 'tech_flux_bug_import',
                'label'               => '[TECHNIQUE] Flux & Imports > Bug import',
                'label_simple'        => "Problème d'import",
                'description'         => "Bug ou dysfonctionnement sur un import existant : flux qui ne passe plus, données non mises à jour, erreurs de parsing XML, annonces disparues. Couvre aussi les problèmes de photos (manquantes, floues, mal importées).",
                'keywords'            => ['bug import', 'flux cassé', 'import bloqué', 'erreur flux', 'ne remonte plus', 'annonces disparues', 'parsing', 'validation xml', 'erreur feed', 'synchronisation', 'ne passe plus', 'photo', 'photos', 'image', 'manquante', 'sans photo'],
                'parent_slug'         => 'tech_flux',
                'is_visible_to_users' => true,
            ],
            [
                'glpi_category_id'    => 18,
                'slug'                => 'tech_photos_medias',
                'label'               => '[TECHNIQUE] Photos / Médias',
                'label_simple'        => 'Photos manquantes ou cassées',
                'description'         => "Problème lié aux photos des annonces. Photos qui ne s'importent pas, photos floues, mauvais ordre, photos manquantes après import, problème d'upload manuel.",
                'keywords'            => ['photo', 'photos', 'image', 'média', 'upload', 'floutée', 'manquante', 'sans photo', 'foto', 'sans photos'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 11,
                'slug'                => 'tech_bug_mails',
                'label'               => '[TECHNIQUE] Bug mails',
                'label_simple'        => 'Problème emails / leads',
                'description'         => "Problème lié aux emails : leads non reçus, mails qui partent en spam, adresses cryptées, bounces, mails de notification qui ne partent pas, configuration SMTP.",
                'keywords'            => ['mail', 'email', 'e-mail', 'spam', 'lead', 'notification', 'bounce', 'smtp', 'adresse mail', 'courriel', 'mails cryptés', 'leads dans les spams', 'changement adresse'],
                'parent_slug'         => null,
                'is_visible_to_users' => true,
            ],
            [
                'glpi_category_id'    => 13,
                'slug'                => 'acces_connexion_droits',
                'label'               => '[ACCÈS] Connexion & Droits',
                'label_simple'        => 'Problème de connexion / accès',
                'description'         => "Problème de connexion ou de droits d'accès. Login impossible, mot de passe oublié, compte bloqué, droits insuffisants, 2FA, changement d'adresse mail.",
                'keywords'            => ['connexion', 'connecter', 'login', 'mot de passe', 'mdp', 'password', 'accès', 'bloqué', 'droits', 'permissions', 'identifiant', 'se connecter', 'changement mail'],
                'parent_slug'         => null,
                'is_visible_to_users' => true,
            ],
            [
                'glpi_category_id'    => 9,
                'slug'                => 'tech_diffusion',
                'label'               => '[TECHNIQUE] Diffusion portails',
                'label_simple'        => 'Annonces pas diffusées',
                'description'         => "Problème de diffusion des annonces vers les portails externes. Annonces non publiées sur Leboncoin, La Centrale, Autoscout24, Truck1, Mascus, TruckScout24, Europe-Camions.",
                'keywords'            => ['diffusion', 'portail', 'leboncoin', 'lbc', 'la centrale', 'autoscout', 'truck1', 'mascus', 'europe-camions', 'truckscout', 'publication', 'publier'],
                'parent_slug'         => null,
                'is_visible_to_users' => true,
            ],
            [
                'glpi_category_id'    => 0, // pas de catégorie dédiée dans GLPI
                'slug'                => 'bug_anomalie',
                'label'               => '[TECHNIQUE] Bug / Anomalie',
                'label_simple'        => 'Bug ou anomalie',
                'description'         => "Bug ou anomalie général sur le site, le backoffice, Stockway ou les traitements serveur. Utilisé par les commerciaux quand le type exact de bug n'est pas connu. L'IA utilise les catégories spécifiques (front, back, backoffice, Stockway).",
                'keywords'            => ['bug', 'anomalie', 'ça ne fonctionne plus', 'erreur', 'problème technique', 'ne marche pas'],
                'parent_slug'         => null,
                'is_visible_to_users' => true,
            ],
            [
                'glpi_category_id'    => 12,
                'slug'                => 'financier_facturation',
                'label'               => '[FINANCIER] Facturation',
                'label_simple'        => 'Question facturation',
                'description'         => "Question ou problème lié à la facturation : facture incorrecte, devis à corriger, pack crédit, prélèvement, avoir, contestation de montant, changement d'abonnement.",
                'keywords'            => ['facture', 'facturation', 'devis', 'crédit', 'paiement', 'montant', 'prix', 'abonnement', 'prélèvement', 'avoir', 'pack credit', 'facturé', 'facture pologne'],
                'parent_slug'         => null,
                'is_visible_to_users' => true,
            ],
            [
                'glpi_category_id'    => 6,
                'slug'                => 'tech_statistiques',
                'label'               => '[TECHNIQUE] Statistiques',
                'label_simple'        => 'Demande de stats / export',
                'description'         => "Demande de statistiques, rapports, exports de données. Extraction Excel, stats de diffusion, rapport d'activité client.",
                'keywords'            => ['statistiques', 'stats', 'rapport', 'export', 'excel', 'extraction', 'métriques', 'données', 'chiffres'],
                'parent_slug'         => null,
                'is_visible_to_users' => true,
            ],
            [
                'glpi_category_id'    => 7,
                'slug'                => 'evolution_fonctionnel',
                'label'               => '[ÉVOLUTION] Fonctionnel et ergonomie',
                'label_simple'        => "Demande d'évolution",
                'description'         => "Demande d'évolution fonctionnelle ou ergonomique. Nouvelle fonctionnalité, amélioration UX, refonte d'interface.",
                'keywords'            => ['évolution', 'fonctionnalité', 'ergonomie', 'nouvelle fonction', 'amélioration ux', 'demande évolution', '2fa', 'feature'],
                'parent_slug'         => null,
                'is_visible_to_users' => true,
            ],
            [
                'glpi_category_id'    => 0, // pas de catégorie GLPI → tri manuel
                'slug'                => 'autre',
                'label'               => 'Autre — Non catégorisé',
                'label_simple'        => 'Autre',
                'description'         => "Demande qui ne correspond à aucune catégorie identifiée. Tri manuel nécessaire par l'équipe support.",
                'keywords'            => [],
                'parent_slug'         => null,
                'is_visible_to_users' => true,
            ],

            // ═══════════════════════════════════════════════════════════════
            // RÉSERVÉES À L'IA (is_visible_to_users=false)
            // ═══════════════════════════════════════════════════════════════

            [
                'glpi_category_id'    => 20,
                'slug'                => 'tech_flux_config_import',
                'label'               => '[TECHNIQUE] Flux & Imports > Configuration import',
                'label_simple'        => "Configuration d'import",
                'description'         => "Configuration ou activation d'un nouveau flux d'import pour un client. Inclut le paramétrage initial du transformateur, le mapping des champs, et la première synchronisation. Concerne les flux XML, CSV, API ou FTP entrants.",
                'keywords'            => ['import', 'flux', 'configuration', 'config', 'paramétrage', 'activer', 'nouveau flux', 'feed', 'xml', 'transformer', 'ftp', 'mapping initial', 'créer import', 'mettre en place'],
                'parent_slug'         => 'tech_flux',
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 21,
                'slug'                => 'tech_flux_amelioration',
                'label'               => '[TECHNIQUE] Flux & Imports > Amélioration import',
                'label_simple'        => "Amélioration d'import",
                'description'         => "Amélioration ou ajustement d'un import existant. Ajout de champs, modification du mapping, changement de format, optimisation de la fréquence de synchronisation.",
                'keywords'            => ['amélioration import', 'ajuster', 'modifier mapping', 'ajouter champ', 'changer format', 'optimiser', 'améliorer flux'],
                'parent_slug'         => 'tech_flux',
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 19,
                'slug'                => 'tech_flux_creation',
                'label'               => '[TECHNIQUE] Flux & Imports > Création import',
                'label_simple'        => 'Nouveau client à importer',
                'description'         => "Demande de création complète d'un nouvel import pour un client. Nouveau client à connecter, nouveau portail source, nouveau format à intégrer.",
                'keywords'            => ['création import', 'nouveau client', 'créer flux', 'nouveau import', 'connecter', 'intégrer', 'nouveau portail', 'importation à faire'],
                'parent_slug'         => 'tech_flux',
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 9,
                'slug'                => 'tech_flux',
                'label'               => '[TECHNIQUE] Flux & Imports',
                'label_simple'        => 'Import / Flux (autre)',
                'description'         => "Problème général lié aux flux et imports qui ne rentre pas dans une sous-catégorie spécifique.",
                'keywords'            => ['flux', 'import', 'feed', 'synchronisation'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 3,
                'slug'                => 'tech_bug_front',
                'label'               => '[TECHNIQUE] Bug Front / UI',
                'label_simple'        => 'Bug site client',
                'description'         => "Bug visible côté client/utilisateur sur le site vitrine ou les portails de diffusion. Problème d'affichage, de navigation, de rendu des annonces, de formulaires cassés côté front.",
                'keywords'            => ['bug front', 'affichage', 'ui', 'site', 'page blanche', 'erreur affichage', 'ne s\'affiche pas', 'rendu', 'design cassé', 'responsive'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 10,
                'slug'                => 'tech_bug_back',
                'label'               => '[TECHNIQUE] Bug back',
                'label_simple'        => 'Bug serveur',
                'description'         => "Bug dans le backend, les traitements serveur ou les processus automatisés. Erreurs côté serveur, crons qui ne tournent plus, traitements en échec.",
                'keywords'            => ['bug back', 'backend', 'serveur', 'cron', 'erreur serveur', 'traitement', '500', 'erreur interne'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 8,
                'slug'                => 'tech_bug_backoffice',
                'label'               => '[TECHNIQUE] Bug Backoffice',
                'label_simple'        => 'Bug backoffice',
                'description'         => "Bug dans l'interface d'administration Via-Mobilis (backoffice). Fonctionnalité cassée, page qui ne charge pas, action qui ne s'exécute pas correctement dans l'admin.",
                'keywords'            => ['backoffice', 'admin', 'back-office', 'interface admin', 'bo', 'back office'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 28,
                'slug'                => 'tech_bug_stockway',
                'label'               => '[TECHNIQUE] Bug Stockway',
                'label_simple'        => 'Bug Stockway',
                'description'         => "Bug spécifique à la plateforme Stockway. Problème lié aux fonctionnalités propres à Stockway, distinctes du backoffice Via-Mobilis principal.",
                'keywords'            => ['stockway', 'bug stockway', 'plateforme stockway'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 27,
                'slug'                => 'tech_support_commercial',
                'label'               => '[TECHNIQUE] Support commercial',
                'label_simple'        => 'Aide technique pour un client',
                'description'         => "Demande de support technique liée à une action commerciale. Le commercial a besoin d'une intervention technique pour un client : correction de données, déblocage de compte, vérification d'un comportement anormal.",
                'keywords'            => ['support commercial', 'client demande', 'intervention', 'demande client', 'signalement client', 'problème client'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 22,
                'slug'                => 'tech_maintenance_clients',
                'label'               => '[TECHNIQUE] Maintenance Clients',
                'label_simple'        => 'Correction données client',
                'description'         => "Opération de maintenance sur le compte ou les données d'un client. Correction de données, nettoyage d'annonces, mise à jour de paramètres, opérations manuelles en base.",
                'keywords'            => ['maintenance', 'correction', 'nettoyage', 'mise à jour client', 'modification compte', 'données client', 'panier', 'désignation'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 16,
                'slug'                => 'tech_nomenclature',
                'label'               => '[TECHNIQUE] Nomenclature',
                'label_simple'        => 'Marque / modèle / catégorie véhicule',
                'description'         => "Problème lié à la nomenclature des véhicules : marques, modèles, catégories de véhicules, types d'engins. Ajout ou correction de marque/modèle, incohérence dans la classification.",
                'keywords'            => ['nomenclature', 'marque', 'modèle', 'catégorie véhicule', 'type engin', 'classification', 'ajout marque', 'incohérence', 'année immat', 'utilitaire', 'différence'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 17,
                'slug'                => 'evolution_dev_produit',
                'label'               => '[ÉVOLUTION] Développement Produit',
                'label_simple'        => 'Développement produit',
                'description'         => "Développement d'une nouvelle fonctionnalité produit planifiée.",
                'keywords'            => ['développement produit', 'dev produit', 'sprint', 'release', 'roadmap'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 29,
                'slug'                => 'evolution_amelioration',
                'label'               => '[ÉVOLUTION] Amélioration Produit',
                'label_simple'        => 'Amélioration existante',
                'description'         => "Amélioration d'une fonctionnalité existante. Optimisation de performance, meilleure gestion d'erreurs.",
                'keywords'            => ['amélioration produit', 'optimisation', 'améliorer', 'perfectionner'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 2,
                'slug'                => 'tech_contrat_groupe',
                'label'               => '[TECHNIQUE] Contrat groupe',
                'label_simple'        => 'Contrat groupe',
                'description'         => "Problème ou configuration lié aux contrats groupe. Visibilité entre entités, partage d'annonces, configuration multi-sites.",
                'keywords'            => ['contrat groupe', 'groupe', 'contrat', 'entité', 'visibilité groupe', 'multi-sites'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 15,
                'slug'                => 'securite_spam',
                'label'               => '[SÉCURITÉ] Spam / Malveillance',
                'label_simple'        => 'Spam / fraude',
                'description'         => "Signalement de spam, tentative de phishing, activité suspecte, compte compromis, annonces frauduleuses.",
                'keywords'            => ['spam', 'malveillance', 'phishing', 'sécurité', 'fraude', 'suspect', 'compromis', 'piraté'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 25,
                'slug'                => 'tech_validation_annonces',
                'label'               => '[TECHNIQUE] Validation annonces',
                'label_simple'        => 'Annonce bloquée en validation',
                'description'         => "Problème lié à la validation des annonces. Annonce bloquée en validation, critères incorrects, annonce rejetée à tort.",
                'keywords'            => ['validation', 'annonce bloquée', 'modération', 'rejet', 'valider annonce', 'demande de validation'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
            [
                'glpi_category_id'    => 24,
                'slug'                => 'donnees_imports',
                'label'               => '[DONNÉES] Imports & Manipulation',
                'label_simple'        => 'Import / manipulation de données',
                'description'         => "Demande d'import ou de manipulation de données en masse. Chargement de catalogue, correction de données en base, import Excel/CSV de véhicules.",
                'keywords'            => ['import données', 'données', 'manipulation données', 'catalogue', 'chargement', 'import excel', 'import csv', 'correction base'],
                'parent_slug'         => null,
                'is_visible_to_users' => false,
            ],
        ];

        foreach ($categories as $catData) {
            GlpiCategoryMap::updateOrCreate(
                [
                    'organization_id' => $org->id,
                    'slug'            => $catData['slug'],
                ],
                [
                    ...$catData,
                    'organization_id' => $org->id,
                    'is_active'       => true,
                ]
            );
        }

        $visible = collect($categories)->where('is_visible_to_users', true)->count();
        $hidden  = collect($categories)->where('is_visible_to_users', false)->count();

        $this->command->info("✓ {$org->name} : " . count($categories) . " catégories ({$visible} visibles commerciaux / {$hidden} IA uniquement).");
        $this->command->info("  IDs GLPI réels mappés (récupérés le 2026-04-04 via GET /ITILCategory).");
    }
}
