// Types alignés sur le futur schéma Supabase (voir .claude/skills/supabase-rls).

export type Level = "debutant" | "intermediaire" | "avance";

export type Lesson = {
  id: string;
  moduleId: string;
  slug: string;
  title: string;
  durationMin: number;
  isPreview: boolean;
  position: number;
  /** Rempli côté serveur après vérification de l'inscription (Bunny Stream). */
  videoId?: string;
  summary: string;
};

export type Module = {
  id: string;
  courseId: string;
  title: string;
  position: number;
  lessons: Lesson[];
};

export type Course = {
  id: string;
  slug: string;
  title: string;
  subtitle: string;
  description: string;
  level: Level;
  category: string;
  priceDzd: number;
  priceEur: number;
  published: boolean;
  instructor: string;
  rating: number;
  students: number;
  outcomes: string[];
  modules: Module[];
  quizId?: string;
};

export type Enrollment = {
  userId: string;
  courseId: string;
  enrolledAt: string;
  expiresAt: string | null;
};

export type LessonProgress = {
  userId: string;
  lessonId: string;
  completedAt: string | null;
};

export type QuizQuestion = {
  id: string;
  prompt: string;
  choices: string[];
  /** Index de la bonne réponse — ne sera jamais envoyé au client en production. */
  answer: number;
};

export type Quiz = {
  id: string;
  courseId: string;
  title: string;
  passScore: number;
  questions: QuizQuestion[];
};

export type Certificate = {
  code: string;
  userName: string;
  courseId: string;
  issuedAt: string;
};

export type Profile = {
  id: string;
  fullName: string;
  email: string;
  role: "student" | "admin";
};
