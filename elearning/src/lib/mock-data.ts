// Données fictives pour la maquette. Remplacées par Supabase via src/lib/data.ts.
import type { Certificate, Course, Enrollment, LessonProgress, Module, Profile, Quiz } from "./types";

type LessonSeed = [title: string, durationMin: number, isPreview?: boolean];
type ModuleSeed = [title: string, lessons: LessonSeed[]];

function slugify(text: string) {
  return text
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/(^-|-$)/g, "");
}

function buildModules(courseId: string, seeds: ModuleSeed[]): Module[] {
  return seeds.map(([title, lessons], mi) => {
    const moduleId = `${courseId}-m${mi + 1}`;
    return {
      id: moduleId,
      courseId,
      title,
      position: mi + 1,
      lessons: lessons.map(([lessonTitle, durationMin, isPreview = false], li) => ({
        id: `${moduleId}-l${li + 1}`,
        moduleId,
        slug: slugify(lessonTitle),
        title: lessonTitle,
        durationMin,
        isPreview,
        position: li + 1,
        videoId: `demo-${moduleId}-l${li + 1}`,
        summary:
          "Dans cette leçon, on avance pas à pas avec des exemples concrets sur graphique, puis un résumé des points clés à retenir.",
      })),
    };
  });
}

export const currentUser: Profile = {
  id: "user-demo",
  fullName: "Amine Benali",
  email: "amine@example.com",
  role: "student",
};

export const courses: Course[] = [
  {
    id: "c1",
    slug: "bases-du-trading",
    title: "Les bases du trading",
    subtitle: "Comprendre les marchés et passer ses premiers ordres sans brûler son capital.",
    description:
      "Un parcours complet pour démarrer : fonctionnement des marchés, lecture d'un graphique, types d'ordres, choix d'un courtier et premiers trades sur compte démo.",
    level: "debutant",
    category: "Fondamentaux",
    priceDzd: 9900,
    priceEur: 39,
    published: true,
    instructor: "Équipe Tradeversity",
    rating: 4.8,
    students: 1240,
    outcomes: [
      "Comprendre comment fonctionnent le Forex, les indices et les cryptos",
      "Lire un graphique en chandeliers",
      "Passer des ordres au marché, limites et stop",
      "Ouvrir et utiliser un compte démo",
    ],
    modules: buildModules("c1", [
      ["Bienvenue", [["Présentation du programme", 6, true], ["Comment suivre ce cours", 4, true]]],
      ["Les marchés financiers", [["Qu'est-ce qu'un marché ?", 12], ["Forex, indices, matières premières, crypto", 18], ["Les horaires de marché", 9]]],
      ["Lire un graphique", [["Les chandeliers japonais", 15], ["Unités de temps", 11], ["Supports et résistances", 17]]],
      ["Passer ses premiers ordres", [["Types d'ordres", 14], ["Choisir un courtier", 10], ["Premier trade sur compte démo", 20]]],
    ]),
    quizId: "q1",
  },
  {
    id: "c2",
    slug: "analyse-technique",
    title: "Analyse technique avancée",
    subtitle: "Structure de marché, liquidité et confluences pour des entrées précises.",
    description:
      "Maîtrisez la structure de marché, les zones de liquidité, les indicateurs utiles et la construction d'un plan de trading reproductible.",
    level: "intermediaire",
    category: "Analyse",
    priceDzd: 19900,
    priceEur: 79,
    published: true,
    instructor: "Équipe Tradeversity",
    rating: 4.9,
    students: 860,
    outcomes: [
      "Identifier la tendance et les changements de structure",
      "Repérer les zones de liquidité",
      "Combiner plusieurs confluences avant d'entrer",
      "Construire un plan de trading écrit",
    ],
    modules: buildModules("c2", [
      ["Structure de marché", [["Tendances et ranges", 16, true], ["Cassures et changements de structure", 19]]],
      ["Liquidité", [["Où se trouve la liquidité ?", 18], ["Prises de liquidité", 21]]],
      ["Indicateurs utiles", [["Moyennes mobiles", 13], ["RSI et divergences", 15], ["Volume", 12]]],
      ["Plan de trading", [["Définir ses setups", 17], ["Checklist avant d'entrer", 10]]],
    ]),
    quizId: "q2",
  },
  {
    id: "c3",
    slug: "gestion-du-risque",
    title: "Gestion du risque et psychologie",
    subtitle: "Protéger son capital et rester discipliné dans la durée.",
    description:
      "La compétence qui sépare les traders rentables des autres : taille de position, ratio risque/rendement, journal de trading et gestion des émotions.",
    level: "avance",
    category: "Risque",
    priceDzd: 14900,
    priceEur: 59,
    published: true,
    instructor: "Équipe Tradeversity",
    rating: 4.7,
    students: 540,
    outcomes: [
      "Calculer sa taille de position",
      "Fixer un risque maximum par trade et par jour",
      "Tenir un journal de trading utile",
      "Gérer la peur, l'avidité et le revenge trading",
    ],
    modules: buildModules("c3", [
      ["Le risque", [["Pourquoi 90 % perdent", 11, true], ["Risque par trade", 14]]],
      ["Taille de position", [["Calcul pas à pas", 18], ["Ratio risque/rendement", 13]]],
      ["Psychologie", [["Les biais du trader", 16], ["Routine et discipline", 12], ["Tenir un journal", 15]]],
    ]),
  },
];

export const enrollments: Enrollment[] = [
  { userId: "user-demo", courseId: "c1", enrolledAt: "2026-09-02T10:00:00Z", expiresAt: null },
  { userId: "user-demo", courseId: "c2", enrolledAt: "2026-10-01T10:00:00Z", expiresAt: null },
];

const c1Lessons = courses[0].modules.flatMap((m) => m.lessons);
const c2Lessons = courses[1].modules.flatMap((m) => m.lessons);

export const progress: LessonProgress[] = [
  ...c1Lessons.map((l) => ({ userId: "user-demo", lessonId: l.id, completedAt: "2026-09-20T10:00:00Z" })),
  ...c2Lessons.slice(0, 3).map((l) => ({ userId: "user-demo", lessonId: l.id, completedAt: "2026-10-10T10:00:00Z" })),
];

export const quizzes: Quiz[] = [
  {
    id: "q1",
    courseId: "c1",
    title: "Quiz final — Les bases du trading",
    passScore: 70,
    questions: [
      { id: "q1-1", prompt: "Que représente la mèche haute d'un chandelier ?", choices: ["Le prix d'ouverture", "Le plus haut atteint sur la période", "Le volume échangé", "Le prix de clôture"], answer: 1 },
      { id: "q1-2", prompt: "Quel ordre s'exécute immédiatement au meilleur prix disponible ?", choices: ["Ordre limite", "Ordre stop", "Ordre au marché", "Ordre OCO"], answer: 2 },
      { id: "q1-3", prompt: "À quoi sert un compte démo ?", choices: ["Gagner de l'argent réel", "S'entraîner sans risquer son capital", "Éviter les frais du courtier", "Trader plus gros"], answer: 1 },
      { id: "q1-4", prompt: "Un support est…", choices: ["Une zone où le prix a tendance à rebondir à la hausse", "Un indicateur de volume", "Une zone où le prix chute toujours", "Un type d'ordre"], answer: 0 },
    ],
  },
  {
    id: "q2",
    courseId: "c2",
    title: "Quiz final — Analyse technique avancée",
    passScore: 70,
    questions: [
      { id: "q2-1", prompt: "Une tendance haussière se caractérise par…", choices: ["Des sommets et creux de plus en plus bas", "Des sommets et creux de plus en plus hauts", "Un prix qui ne bouge pas", "Un volume nul"], answer: 1 },
      { id: "q2-2", prompt: "Une divergence RSI baissière apparaît quand…", choices: ["Le prix fait un plus haut, le RSI un plus bas", "Le prix et le RSI montent ensemble", "Le RSI dépasse 50", "Le volume augmente"], answer: 0 },
      { id: "q2-3", prompt: "Pourquoi chercher des confluences ?", choices: ["Pour trader plus souvent", "Pour augmenter la probabilité d'un setup", "Pour éviter le stop loss", "Pour doubler la taille de position"], answer: 1 },
    ],
  },
];

export const certificates: Certificate[] = [
  { code: "TV-2026-0001", userName: "Amine Benali", courseId: "c1", issuedAt: "2026-09-21T10:00:00Z" },
];

export const testimonials = [
  { name: "Yasmine K.", city: "Alger", text: "Enfin une formation claire, en français, avec des exemples sur de vrais graphiques. J'ai arrêté de trader au hasard." },
  { name: "Karim B.", city: "Oran", text: "Le module sur la gestion du risque a tout changé. Je sais maintenant combien je risque avant chaque trade." },
  { name: "Sofiane M.", city: "Annaba", text: "Paiement par carte Edahabia, accès immédiat, vidéos fluides même en 4G. Rien à redire." },
];

export const adminStats = {
  revenueDzd: 4_870_000,
  sales30d: 312,
  students: 2640,
  completionRate: 64,
  recentSales: [
    { id: "s1", student: "Nadia R.", course: "Analyse technique avancée", amountDzd: 19900, method: "Edahabia", date: "2026-10-17T14:12:00Z" },
    { id: "s2", student: "Walid T.", course: "Les bases du trading", amountDzd: 9900, method: "CIB", date: "2026-10-17T11:40:00Z" },
    { id: "s3", student: "Lina H.", course: "Gestion du risque et psychologie", amountDzd: 14900, method: "Stripe", date: "2026-10-16T19:05:00Z" },
    { id: "s4", student: "Mehdi A.", course: "Les bases du trading", amountDzd: 9900, method: "Edahabia", date: "2026-10-16T09:22:00Z" },
  ],
};
