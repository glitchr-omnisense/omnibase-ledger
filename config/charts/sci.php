<?php

use Base\Ledger\Enum\AccountType as T;
use Base\Ledger\Enum\TaxKind as K;

/*
 * The plan comptable général (PCG) accounts a French real-estate SCI uses:
 * rents and recovered charges in, property costs, loan interest and taxes
 * out, associates' current accounts, tenants' deposits, the bank and a
 * clearing account for card payments (Stripe: payments into 5112, payouts
 * from it to 512, fees from it to 6278). Tenants, suppliers and associates
 * get their own auxiliary accounts under 411, 401 and 455 (Parties).
 *
 * accounts: number => [label, type]
 * taxes: the French VAT codes, code => [label, rate in basis points, kind,
 * account, deductible account (a reverse charge's)] - V for sales, A for
 * purchases, I for fixed assets, EXO exempt, AIC an intra-EU acquisition.
 * A book outside VAT (VatRegime::NONE, an SCI's default) has them unused.
 */
return [
    'accounts' => [
        // Classe 1 - capitaux
        '101' => ['Capital social', T::EQUITY],
        '1061' => ['Réserve légale', T::EQUITY],
        '1068' => ['Autres réserves', T::EQUITY],
        '108' => ['Compte de l\'exploitant', T::EQUITY],
        '110' => ['Report à nouveau (solde créditeur)', T::EQUITY],
        '119' => ['Report à nouveau (solde débiteur)', T::EQUITY],
        '120' => ['Résultat de l\'exercice (bénéfice)', T::EQUITY],
        '129' => ['Résultat de l\'exercice (perte)', T::EQUITY],
        '164' => ['Emprunts auprès des établissements de crédit', T::LIABILITY],
        '1688' => ['Intérêts courus sur emprunts', T::LIABILITY],
        '165' => ['Dépôts et cautionnements reçus (dépôts de garantie)', T::LIABILITY],

        // Classe 2 - immobilisations
        '211' => ['Terrains', T::ASSET],
        '213' => ['Constructions', T::ASSET],
        '2135' => ['Installations générales, agencements, aménagements des constructions', T::ASSET],
        '218' => ['Autres immobilisations corporelles', T::ASSET],
        '231' => ['Immobilisations corporelles en cours', T::ASSET],
        '275' => ['Dépôts et cautionnements versés', T::ASSET],
        '2813' => ['Amortissements des constructions', T::ASSET],
        '28135' => ['Amortissements des installations et agencements', T::ASSET],

        // Classe 4 - tiers
        '401' => ['Fournisseurs', T::LIABILITY],
        '404' => ['Fournisseurs d\'immobilisations', T::LIABILITY],
        '408' => ['Fournisseurs - factures non parvenues', T::LIABILITY],
        '411' => ['Clients - locataires', T::ASSET],
        '416' => ['Clients douteux ou litigieux', T::ASSET],
        '418' => ['Clients - produits non encore facturés', T::ASSET],
        '419' => ['Clients créditeurs (avances et trop-perçus)', T::LIABILITY],
        '4452' => ['TVA due intracommunautaire', T::LIABILITY],
        '44551' => ['TVA à décaisser', T::LIABILITY],
        '44562' => ['TVA déductible sur immobilisations', T::ASSET],
        '44566' => ['TVA déductible sur autres biens et services', T::ASSET],
        '44567' => ['Crédit de TVA à reporter', T::ASSET],
        '44571' => ['TVA collectée', T::LIABILITY],
        '455' => ['Associés - comptes courants', T::LIABILITY],
        '4551' => ['Associés - apports en compte courant', T::LIABILITY],
        '457' => ['Associés - dividendes à payer', T::LIABILITY],
        '467' => ['Autres comptes débiteurs ou créditeurs', T::ASSET],
        '4711' => ['Opérations en attente de classement', T::ASSET],
        '486' => ['Charges constatées d\'avance', T::ASSET],
        '487' => ['Produits constatés d\'avance', T::LIABILITY],

        // Classe 5 - financiers
        '5112' => ['Paiements à encaisser (cartes, Stripe)', T::ASSET],
        '512' => ['Banques', T::ASSET],
        '580' => ['Virements internes', T::ASSET],

        // Classe 6 - charges
        '6061' => ['Fournitures non stockables (eau, énergie)', T::EXPENSE],
        '6063' => ['Fournitures d\'entretien et de petit équipement', T::EXPENSE],
        '611' => ['Sous-traitance générale (gestion locative)', T::EXPENSE],
        '614' => ['Charges locatives et de copropriété', T::EXPENSE],
        '615' => ['Entretien et réparations', T::EXPENSE],
        '6152' => ['Entretien et réparations sur biens immobiliers', T::EXPENSE],
        '616' => ['Primes d\'assurance', T::EXPENSE],
        '6226' => ['Honoraires', T::EXPENSE],
        '6227' => ['Frais d\'actes et de contentieux', T::EXPENSE],
        '6231' => ['Annonces et insertions', T::EXPENSE],
        '626' => ['Frais postaux et de télécommunications', T::EXPENSE],
        '627' => ['Services bancaires et assimilés', T::EXPENSE],
        '6278' => ['Autres frais et commissions (paiements en ligne)', T::EXPENSE],
        '635' => ['Autres impôts, taxes et versements assimilés', T::EXPENSE],
        '63512' => ['Taxes foncières', T::EXPENSE],
        '6354' => ['Droits d\'enregistrement et de timbre', T::EXPENSE],
        '654' => ['Pertes sur créances irrécouvrables', T::EXPENSE],
        '658' => ['Charges diverses de gestion courante', T::EXPENSE],
        '6611' => ['Intérêts des emprunts et dettes', T::EXPENSE],
        '6615' => ['Intérêts des comptes courants', T::EXPENSE],
        '671' => ['Charges exceptionnelles sur opérations de gestion', T::EXPENSE],
        '681' => ['Dotations aux amortissements, dépréciations et provisions', T::EXPENSE],
        '695' => ['Impôts sur les bénéfices', T::EXPENSE],

        // Classe 7 - produits
        '706' => ['Prestations de services (loyers)', T::INCOME],
        '708' => ['Produits des activités annexes (charges récupérées)', T::INCOME],
        '7083' => ['Locations diverses (parkings, caves)', T::INCOME],
        '7088' => ['Autres produits d\'activités annexes', T::INCOME],
        '758' => ['Produits divers de gestion courante', T::INCOME],
        '768' => ['Autres produits financiers', T::INCOME],
        '771' => ['Produits exceptionnels sur opérations de gestion', T::INCOME],
        '781' => ['Reprises sur amortissements, dépréciations et provisions', T::INCOME],
        '791' => ['Transferts de charges d\'exploitation', T::INCOME],
        // Classe 8 - comptes spéciaux: what a bank account's opening entry is balanced against
        '890' => ['Bilan d\'ouverture', T::EQUITY],
    ],
    'journals' => [
        'BQ' => 'Banque',
        'VT' => 'Ventes (quittancement)',
        'AC' => 'Achats',
        'OD' => 'Opérations diverses',
        'AN' => 'À-nouveaux',
    ],
    'taxes' => [
        'V20' => ['TVA collectée 20 %', 2000, K::COLLECTED, '44571', null],
        'V10' => ['TVA collectée 10 %', 1000, K::COLLECTED, '44571', null],
        'V55' => ['TVA collectée 5,5 %', 550, K::COLLECTED, '44571', null],
        'V21' => ['TVA collectée 2,1 %', 210, K::COLLECTED, '44571', null],
        'A20' => ['TVA déductible 20 %', 2000, K::DEDUCTIBLE, '44566', null],
        'A10' => ['TVA déductible 10 %', 1000, K::DEDUCTIBLE, '44566', null],
        'A55' => ['TVA déductible 5,5 %', 550, K::DEDUCTIBLE, '44566', null],
        'A21' => ['TVA déductible 2,1 %', 210, K::DEDUCTIBLE, '44566', null],
        'I20' => ['TVA déductible sur immobilisations 20 %', 2000, K::DEDUCTIBLE_ASSETS, '44562', null],
        'EXO' => ['Exonéré', 0, K::EXEMPT, null, null],
        'AIC20' => ['Acquisition intracommunautaire 20 % (autoliquidée)', 2000, K::REVERSE_CHARGE, '4452', '44566'],
    ],
];
