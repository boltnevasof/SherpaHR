export interface User { id: number; name: string }
export interface Project { id: number; name: string; description: string; archived: boolean; owner_id: number; task_count: number; members: User[] }
export type TaskStatus = 'open' | 'in_progress' | 'blocked' | 'done';
export interface Task { id: number; project_id: number; title: string; description: string; status: TaskStatus; assignee_id: number | null; due_at: string | null; updated_at: string; version: number }
export interface Comment { id: number; task_id: number; user_id: number; body: string; created_at: string; author: string }
export interface TaskDetail extends Task { comments: Comment[] }
export interface Context { user: User; users: User[]; server_now: string; seeded_at: string }
export type AttentionCode = 'overdue' | 'blocked' | 'mention' | 'due_soon' | 'unassigned' | 'stale';
export interface AttentionRule { code: AttentionCode; label: string; severity: 'high' | 'medium'; description: string }
export interface AttentionReason { code: AttentionCode; label: string; severity: 'high' | 'medium'; detail: string }
export interface AttentionItem {
  task: Task; project_name: string; assignee_name: string | null; reasons: AttentionReason[];
  comment_count: number; last_comment: { author: string; body: string; created_at: string } | null; last_activity_at: string;
}
export interface Attention { generated_at: string; projects: { id: number; name: string }[]; rules: AttentionRule[]; items: AttentionItem[] }
