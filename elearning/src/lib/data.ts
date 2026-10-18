// Couche d'accès aux données. Aujourd'hui : données fictives.
// Demain : requêtes Supabase (mêmes signatures), sans toucher aux pages.
import { adminStats, certificates, courses, currentUser, enrollments, progress, quizzes, testimonials } from "./mock-data";
import type { Course, Lesson } from "./types";
import { courseLessons } from "./utils";

export function getCourses(): Course[] {
  return courses.filter((c) => c.published);
}

export function getCourseBySlug(slug: string): Course | undefined {
  return courses.find((c) => c.slug === slug && c.published);
}

export function getCurrentUser() {
  return currentUser;
}

export function getMyCourses() {
  return enrollments
    .filter((e) => e.userId === currentUser.id)
    .map((e) => courses.find((c) => c.id === e.courseId))
    .filter((c): c is Course => Boolean(c))
    .map((course) => ({ course, ...getCourseProgress(course) }));
}

export function isEnrolled(courseId: string) {
  return enrollments.some((e) => e.userId === currentUser.id && e.courseId === courseId);
}

export function getCompletedLessonIds(): Set<string> {
  return new Set(progress.filter((p) => p.userId === currentUser.id && p.completedAt).map((p) => p.lessonId));
}

export function getCourseProgress(course: Course) {
  const lessons = courseLessons(course);
  const done = getCompletedLessonIds();
  const completed = lessons.filter((l) => done.has(l.id)).length;
  const next = lessons.find((l) => !done.has(l.id)) ?? lessons[lessons.length - 1];
  return { completed, total: lessons.length, percent: Math.round((completed / lessons.length) * 100), nextLesson: next };
}

export function getLesson(courseSlug: string, lessonSlug: string) {
  const course = getCourseBySlug(courseSlug);
  if (!course) return undefined;
  const lessons = courseLessons(course);
  const index = lessons.findIndex((l) => l.slug === lessonSlug);
  if (index === -1) return undefined;
  const lesson: Lesson = lessons[index];
  return { course, lesson, prev: lessons[index - 1], next: lessons[index + 1] };
}

export function getQuiz(courseSlug: string, quizId: string) {
  const course = getCourseBySlug(courseSlug);
  const quiz = quizzes.find((q) => q.id === quizId && q.courseId === course?.id);
  return course && quiz ? { course, quiz } : undefined;
}

export function getCertificate(code: string) {
  const cert = certificates.find((c) => c.code === code);
  if (!cert) return undefined;
  const course = courses.find((c) => c.id === cert.courseId);
  return course ? { cert, course } : undefined;
}

export function getAllCertificateCodes() {
  return certificates.map((c) => c.code);
}

export function getTestimonials() {
  return testimonials;
}

export function getAdminStats() {
  return adminStats;
}
