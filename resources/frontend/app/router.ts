import { createRouter, createWebHistory, type Router, type RouterHistory } from 'vue-router'

import HomePage from '@/pages/HomePage.vue'
import NotFoundPage from '@/pages/NotFoundPage.vue'

export function createEeoRouter(history: RouterHistory = createWebHistory()): Router {
  return createRouter({
    history,
    routes: [
      { path: '/', name: 'accueil', component: HomePage },
      { path: '/:pathMatch(.*)*', name: 'introuvable', component: NotFoundPage },
    ],
  })
}
