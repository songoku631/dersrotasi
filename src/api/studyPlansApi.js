import { apiRequest } from './client'

export const getStudyPlan = (user, weekStart, signal) => apiRequest(`/api/study-plans?week_start=${encodeURIComponent(weekStart)}`, { user, auth: true, signal })
export const addStudyTask = (user, weekStart, task, signal) => apiRequest('/api/study-plans/tasks', { user, auth: true, method: 'POST', body: { ...task, week_start: weekStart }, signal })
export const updateStudyTask = (user, taskId, task, signal) => apiRequest(`/api/study-plans/tasks/${taskId}`, { user, auth: true, method: 'PUT', body: task, signal })
export const deleteStudyTask = (user, taskId, signal) => apiRequest(`/api/study-plans/tasks/${taskId}`, { user, auth: true, method: 'DELETE', signal })
export const clearStudyPlan = (user, weekStart, signal) => apiRequest('/api/study-plans', { user, auth: true, method: 'DELETE', body: { week_start: weekStart }, signal })
export const generateStudyPlan = (user, weekStart, form, requestId, signal) => apiRequest('/api/study-plans/ai-generate', { user, auth: true, method: 'POST', body: { ...form, week_start: weekStart, request_id: requestId }, signal })
export const applyGeneratedStudyPlan = (user, weekStart, tasks, signal) => apiRequest('/api/study-plans/ai-apply', { user, auth: true, method: 'POST', body: { week_start: weekStart, tasks }, signal })
