<?php

namespace App\DataFixtures;

use App\Entity\AdminUser;
use App\Entity\BringItem;
use App\Entity\Category;
use App\Entity\Extra;
use App\Entity\Highlight;
use App\Entity\IncludedItem;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher) {}

    public function load(ObjectManager $manager): void
    {
        // ── Admin user ──────────────────────────────────────────────
        $admin = new AdminUser();
        $admin->setEmail('admin@andiamo.tn');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'andiamo2026'));
        $manager->persist($admin);

        // ── Experience categories ────────────────────────────────────
        foreach ($this->getData() as $data) {
            $category = new Category();
            $category->setName($data['name']);
            $category->setSlug($data['slug']);
            $category->setTeaser($data['teaser']);
            $category->setDescription($data['description']);
            $category->setBasePrice($data['basePrice']);
            // Images are null by default — uploaded via admin dashboard

            foreach ($data['highlights'] as $h) {
                $highlight = new Highlight();
                $highlight->setIcon($h['icon']);
                $highlight->setLabel($h['label']);
                $highlight->setValue($h['value']);
                $category->addHighlight($highlight);
                $manager->persist($highlight);
            }

            foreach ($data['extras'] as $e) {
                $extra = new Extra();
                $extra->setName($e['name']);
                $extra->setPrice($e['price']);
                $category->addExtra($extra);
                $manager->persist($extra);
            }

            foreach ($data['included'] as $item) {
                $included = new IncludedItem();
                $included->setIcon($item['icon']);
                $included->setLabel($item['label']);
                $category->addIncludedItem($included);
                $manager->persist($included);
            }

            foreach ($data['bring'] as $item) {
                $bring = new BringItem();
                $bring->setIcon($item['icon']);
                $bring->setLabel($item['label']);
                $category->addBringItem($bring);
                $manager->persist($bring);
            }

            $manager->persist($category);
        }

        $manager->flush();
    }

    private function getData(): array
    {
        return [
            // ── 1. SUP Day ──────────────────────────────────────────
            [
                'name'        => 'SUP Day',
                'slug'        => 'sup-day',
                'teaser'      => "Une journée sur l'eau en stand-up paddle le long des côtes de Hammamet.",
                'description' => "Montez sur votre planche et explorez la Méditerranée tunisienne d'une façon unique : debout, au rythme des vagues, le long des côtes préservées entre Hammamet et Nabeul. La matinée commence par une initiation sur la plage — équilibre, pagaie, virages — encadrée par nos guides certifiés. Vient ensuite la sortie en mer vers des criques accessibles uniquement depuis l'eau : transparentes, calmes, inhabitées. Aucune expérience préalable n'est requise. Le matériel est fourni, l'encadrement est inclus, et l'assurance activité couvre toute la journée.",
                'basePrice'   => '80.00',
                'highlights'  => [
                    ['icon' => 'clock',   'label' => 'Durée',   'value' => 'Journée complète — 8h'],
                    ['icon' => 'zap',     'label' => 'Niveau',  'value' => 'Facile — dès 8 ans'],
                    ['icon' => 'map-pin', 'label' => 'Lieu',    'value' => 'Hammamet, Cap Bon'],
                    ['icon' => 'users',   'label' => 'Groupe',  'value' => '6 à 12 personnes'],
                ],
                'included' => [
                    ['icon' => 'package', 'label' => 'Planche SUP + pagaie fournis'],
                    ['icon' => 'shield',  'label' => 'Gilet de sauvetage'],
                    ['icon' => 'users',   'label' => 'Initiation et encadrement par un guide certifié'],
                    ['icon' => 'shield',  'label' => 'Assurance activité nautique'],
                    ['icon' => 'car',     'label' => 'Transport aller-retour depuis le point de rendez-vous'],
                ],
                'bring' => [
                    ['icon' => 'shirt',      'label' => 'Maillot de bain'],
                    ['icon' => 'sun',        'label' => 'Crème solaire et lunettes de soleil'],
                    ['icon' => 'droplet',    'label' => 'Bouteille d\'eau (1 L minimum)'],
                    ['icon' => 'footprints', 'label' => 'Chaussures aquatiques ou sandales'],
                    ['icon' => 'bag',        'label' => 'Changement de vêtements et serviette'],
                ],
                'extras' => [
                    ['name' => 'Lunch box (sandwich + boisson + fruit)', 'price' => '15.00'],
                    ['name' => 'Pack photos & vidéo professionnelles',    'price' => '25.00'],
                ],
            ],

            // ── 2. Beach Day ────────────────────────────────────────
            [
                'name'        => 'Beach Day',
                'slug'        => 'beach-day',
                'teaser'      => "Criques secrètes, snorkeling et coucher de soleil sur le Cap Bon.",
                'description' => "Oubliez les plages bondées. Notre Beach Day vous emmène vers des criques vierges du Cap Bon, accessibles uniquement par bateau — pas de routes, pas de foule, juste l'eau cristalline et le silence. La journée se déroule à votre rythme : baignade, snorkeling dans les herbiers de posidonie, détente sur des rochers plats ou des petites plages de sable blanc. En fin de journée, vous profitez du coucher de soleil depuis la mer avant de rentrer. Nos guides locaux partagent la connaissance intime du littoral qu'ils explorent depuis l'enfance.",
                'basePrice'   => '60.00',
                'highlights'  => [
                    ['icon' => 'clock',   'label' => 'Durée',  'value' => 'Journée complète — 9h'],
                    ['icon' => 'zap',     'label' => 'Niveau', 'value' => 'Très facile — tout âge'],
                    ['icon' => 'map-pin', 'label' => 'Lieu',   'value' => 'Cap Bon, Kélibia'],
                    ['icon' => 'users',   'label' => 'Groupe', 'value' => '8 à 15 personnes'],
                ],
                'included' => [
                    ['icon' => 'car',    'label' => 'Transfert en bateau vers les criques'],
                    ['icon' => 'users',  'label' => 'Guide local à bord'],
                    ['icon' => 'shield', 'label' => 'Masque de snorkeling fourni'],
                    ['icon' => 'shield', 'label' => 'Assurance activité nautique'],
                ],
                'bring' => [
                    ['icon' => 'shirt',      'label' => 'Maillot de bain et serviette'],
                    ['icon' => 'sun',        'label' => 'Crème solaire (résistante à l\'eau) et chapeau'],
                    ['icon' => 'footprints', 'label' => 'Sandales de plage ou chaussures aquatiques'],
                    ['icon' => 'droplet',    'label' => 'Eau et collation personnelle'],
                    ['icon' => 'bag',        'label' => 'Sac étanche pour vos affaires'],
                ],
                'extras' => [
                    ['name' => 'Palmes de snorkeling',          'price' => '15.00'],
                    ['name' => 'Déjeuner barbecue plage',        'price' => '20.00'],
                    ['name' => 'Apéritif coucher de soleil',     'price' => '25.00'],
                ],
            ],

            // ── 3. Jungle Trek ──────────────────────────────────────
            [
                'name'        => 'Jungle Trek',
                'slug'        => 'jungle-trek',
                'teaser'      => "Forêts de chêne-liège, cascades et sentiers secrets dans la Kroumirie.",
                'description' => "La Kroumirie est l'un des derniers massifs forestiers denses d'Afrique du Nord : chênes-lièges centenaires, fougères géantes, ruisseaux froids et cascades cachées. Notre trek part d'Aïn Draham en altitude et descend sur 8 heures à travers des sentiers que seuls les locaux connaissent. La première partie de la journée traverse la forêt dense en montée douce ; la descente de l'après-midi longe un ruisseau jusqu'à une source naturelle où vous pouvez vous baigner. Le guide partage les noms locaux des plantes, les légendes berbères du massif, et l'histoire du liège — première ressource naturelle exportée de la région.",
                'basePrice'   => '90.00',
                'highlights'  => [
                    ['icon' => 'clock',   'label' => 'Durée',  'value' => '8 heures de marche'],
                    ['icon' => 'zap',     'label' => 'Niveau', 'value' => 'Modéré — bonne condition physique'],
                    ['icon' => 'map-pin', 'label' => 'Lieu',   'value' => 'Aïn Draham, Kroumirie'],
                    ['icon' => 'users',   'label' => 'Groupe', 'value' => '6 à 10 personnes'],
                ],
                'included' => [
                    ['icon' => 'users',   'label' => 'Guide local certifié pour toute la journée'],
                    ['icon' => 'shield',  'label' => 'Assurance randonnée'],
                    ['icon' => 'map-pin', 'label' => 'Carte du sentier fournie'],
                    ['icon' => 'package', 'label' => 'Kit de premiers secours'],
                ],
                'bring' => [
                    ['icon' => 'footprints', 'label' => 'Chaussures de randonnée fermées (obligatoires)'],
                    ['icon' => 'droplet',    'label' => '2 litres d\'eau minimum'],
                    ['icon' => 'bag',        'label' => 'Veste légère ou imperméable (la forêt est fraîche)'],
                    ['icon' => 'sun',        'label' => 'Crème solaire et répulsif anti-insectes'],
                    ['icon' => 'bag',        'label' => 'Snack ou barre énergétique'],
                ],
                'extras' => [
                    ['name' => 'Panier repas (spécialités de la région)', 'price' => '15.00'],
                    ['name' => 'Pack photos & vidéo professionnelles',     'price' => '30.00'],
                    ['name' => 'Transport depuis Tunis (aller-retour)',    'price' => '25.00'],
                ],
            ],

            // ── 4. Camping Night ────────────────────────────────────
            [
                'name'        => 'Camping Night',
                'slug'        => 'camping-night',
                'teaser'      => "Nuit sous les étoiles du Grand Erg Oriental — silence absolu et ciel infini.",
                'description' => "À Douz, la route s'arrête et le désert commence. Notre camp est installé sur une dune de l'erg, loin de tout village et de toute lumière artificielle. La soirée commence par une balade à dos de chameau au coucher du soleil — l'occasion de voir les dunes changer de couleur en quelques minutes. Au retour au camp, dîner traditionnel autour du feu (tajine, kefteji, thé à la menthe servi trois fois, comme il se doit). La nuit est fraîche, parfois froide — mais le sac de couchage est fourni, et le silence est total. Au matin, lever avec le soleil sur les dunes. Petit-déjeuner chaud, retour calme.",
                'basePrice'   => '120.00',
                'highlights'  => [
                    ['icon' => 'clock',   'label' => 'Durée',  'value' => '24h — départ soir, retour lendemain matin'],
                    ['icon' => 'zap',     'label' => 'Niveau', 'value' => 'Facile — aucune condition physique requise'],
                    ['icon' => 'map-pin', 'label' => 'Lieu',   'value' => 'Douz, Grand Erg Oriental'],
                    ['icon' => 'users',   'label' => 'Groupe', 'value' => '4 à 8 personnes'],
                ],
                'included' => [
                    ['icon' => 'package', 'label' => 'Tente de camp et sac de couchage'],
                    ['icon' => 'utensils','label' => 'Dîner traditionnel autour du feu'],
                    ['icon' => 'utensils','label' => 'Petit-déjeuner au lever du soleil'],
                    ['icon' => 'users',   'label' => 'Guide et chamelier accompagnateurs'],
                    ['icon' => 'shield',  'label' => 'Assurance séjour désert'],
                ],
                'bring' => [
                    ['icon' => 'shirt',      'label' => 'Vêtements chauds — les nuits sont fraîches même en été'],
                    ['icon' => 'footprints', 'label' => 'Chaussures fermées (pas de tongs dans le sable)'],
                    ['icon' => 'sun',        'label' => 'Crème solaire haute protection et lunettes'],
                    ['icon' => 'bag',        'label' => 'Médicaments personnels si nécessaire'],
                    ['icon' => 'zap',        'label' => 'Lampe de poche ou frontale'],
                ],
                'extras' => [
                    ['name' => 'Tente premium double (plus confortable)',  'price' => '30.00'],
                    ['name' => 'Dîner amélioré (méchoui + plats supp.)',   'price' => '25.00'],
                    ['name' => 'Balade à dos de chameau (1 heure)',        'price' => '40.00'],
                    ['name' => 'Transport depuis Tunis (aller-retour)',    'price' => '50.00'],
                ],
            ],
        ];
    }
}
