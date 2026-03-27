<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

class SaasPlanController extends Controller
{
    public function index()
    {
        $plans = [
            [
                'id' => 'pro',
                'name' => 'المحترف',
                'name_fr' => 'Pro',
                'price' => 10900,
                'product_limit' => 2000,
                'user_limit' => 10,
                'extra_user_price' => 1000,
                'features' => [
                    'حتى 2,000 منتج',
                    'حتى 10 مستخدمين',
                    '+ 1,000 د.ج لكل مستخدم إضافي',
                    'كل الوحدات والميزات',
                    'التوصيل وتتبع GPS',
                    'البيع المتنقل (Cashvan)',
                    'تطبيقات الموبايل',
                    'دعم فني أولوي',
                ],
                'features_fr' => [
                    'Jusqu\'à 2 000 produits',
                    'Jusqu\'à 10 utilisateurs',
                    '+ 1 000 DA / utilisateur supp.',
                    'Tous les modules et fonctionnalités',
                    'Livraison & suivi GPS',
                    'Cashvan (vente mobile)',
                    'Apps mobiles',
                    'Support prioritaire',
                ],
            ],
            [
                'id' => 'pro_ai',
                'name' => 'المحترف + ذكاء اصطناعي',
                'name_fr' => 'Pro + IA',
                'price' => 16900,
                'product_limit' => 5000,
                'user_limit' => 20,
                'extra_user_price' => 1000,
                'features' => [
                    'كل مميزات المحترف',
                    'حتى 5,000 منتج',
                    'حتى 20 مستخدم',
                    'تحليلات ذكية بالذكاء الاصطناعي',
                    'توقعات المبيعات والطلبات',
                    'تقارير وتوصيات تلقائية',
                    'دعم فني VIP',
                ],
                'features_fr' => [
                    'Tout dans Pro',
                    'Jusqu\'à 5 000 produits',
                    'Jusqu\'à 20 utilisateurs',
                    'Analyses intelligentes par IA',
                    'Prévisions ventes & commandes',
                    'Rapports & recommandations auto',
                    'Support VIP',
                ],
            ],
            [
                'id' => 'enterprise',
                'name' => 'المؤسسات',
                'name_fr' => 'Entreprise',
                'price' => null,
                'product_limit' => null,
                'user_limit' => null,
                'extra_user_price' => 0,
                'contact_sales' => true,
                'features' => [
                    'منتجات ومستخدمين بلا حدود',
                    'كل مميزات المحترف + ذكاء اصطناعي',
                    'خوادم مخصصة',
                    'تكاملات مخصصة (API)',
                    'مدير حساب مخصص',
                    'تدريب فريقك',
                    'اتفاقية مستوى خدمة (SLA)',
                ],
                'features_fr' => [
                    'Produits & utilisateurs illimités',
                    'Tout dans Pro + IA',
                    'Serveurs dédiés',
                    'Intégrations personnalisées (API)',
                    'Gestionnaire de compte dédié',
                    'Formation de votre équipe',
                    'SLA garanti',
                ],
            ],
        ];

        return response()->json($plans);
    }
}
