import { Component, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Api } from './api';
import { Attention, AttentionCode, AttentionItem, Comment, Context, Project, Task, TaskDetail, TaskStatus } from './models';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './app.html',
})
export class AppComponent {
  api = inject(Api);
  context = signal<Context | null>(null);
  projects = signal<Project[]>([]);
  project = signal<Project | null>(null);
  /** 'attention' — сводка руководителя по всем его активным проектам. */
  view = signal<'attention' | 'project'>('project');
  attention = signal<Attention | null>(null);
  attentionLoading = signal(false);
  attentionFilter = signal<AttentionCode | ''>('');
  /** Проект задачи, открытой в карточке: на сводке он не совпадает с выбранным в меню. */
  detailProject = signal<Project | null>(null);
  tasks = signal<Task[]>([]);
  detail = signal<TaskDetail | null>(null);
  loading = signal(false);
  saving = signal(false);
  error = signal('');
  notice = signal('');
  status = '';
  search = '';
  comment = '';
  creating = signal(false);
  draft = this.emptyDraft();
  private generation = 0;
  private detailGeneration = 0;
  private attentionGeneration = 0;
  readonly statuses: { value: TaskStatus; label: string }[] = [
    { value: 'open', label: 'Открыта' }, { value: 'in_progress', label: 'В работе' },
    { value: 'blocked', label: 'Заблокирована' }, { value: 'done', label: 'Готово' },
  ];

  constructor() { void this.load(); }

  async load(): Promise<void> {
    const generation = ++this.generation;
    this.loading.set(true);
    this.error.set('');
    this.closeDetail();
    try {
      const [context, projects] = await Promise.all([
        this.api.request<Context>('/context'), this.api.request<Project[]>('/projects'),
      ]);
      if (generation !== this.generation) return;
      this.context.set(context);
      this.projects.set(projects);
      const current = projects.find(p => p.id === this.project()?.id);
      if (current) await this.selectProject(current);
      // Руководитель начинает день со сводки; остальные — как раньше, с первого проекта.
      else if (this.isManager()) await this.openAttention();
      else if (projects[0]) await this.selectProject(projects[0]);
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  switchUser(value: string): void {
    this.api.userId = Number(value);
    this.project.set(null);
    this.projects.set([]);
    this.tasks.set([]);
    this.context.set(null);
    this.attention.set(null);
    this.attentionFilter.set('');
    this.view.set('project');
    this.notice.set('');
    void this.load();
  }

  async selectProject(project: Project): Promise<void> {
    const generation = ++this.generation;
    this.loading.set(true);
    this.error.set('');
    this.notice.set('');
    this.view.set('project');
    this.project.set(project);
    this.tasks.set([]);
    this.status = '';
    this.search = '';
    this.closeDetail();
    try {
      const tasks = await this.api.request<Task[]>(`/projects/${project.id}/tasks`);
      if (generation === this.generation) this.tasks.set(tasks);
    } catch (error) {
      if (generation === this.generation) this.fail(error);
    } finally {
      if (generation === this.generation) this.loading.set(false);
    }
  }

  filteredTasks(): Task[] {
    return this.tasks().filter(task => (!this.status || task.status === this.status)
      && task.title.toLocaleLowerCase('ru').includes(this.search.toLocaleLowerCase('ru')));
  }

  /** Есть ли у пользователя активный проект, где он руководитель. */
  isManager(): boolean {
    const user = this.context()?.user.id;
    return this.projects().some(p => p.owner_id === user && !p.archived);
  }

  async openAttention(): Promise<void> {
    ++this.generation;
    this.view.set('attention');
    this.project.set(null);
    this.tasks.set([]);
    this.error.set('');
    this.notice.set('');
    this.closeDetail();
    await this.refreshAttention();
  }

  /** Перечитать сводку. После ответа или правки задачи причины могут исчезнуть. */
  async refreshAttention(): Promise<void> {
    const generation = ++this.attentionGeneration;
    const userId = this.api.userId;
    this.attentionLoading.set(true);
    try {
      const data = await this.api.request<Attention>('/attention');
      if (generation === this.attentionGeneration && userId === this.api.userId) this.attention.set(data);
    } catch (error) {
      if (generation === this.attentionGeneration) this.fail(error);
    } finally {
      if (generation === this.attentionGeneration) this.attentionLoading.set(false);
    }
  }

  attentionItems(): AttentionItem[] {
    const filter = this.attentionFilter();
    return (this.attention()?.items ?? []).filter(item => !filter || item.reasons.some(r => r.code === filter));
  }

  ruleCount(code: AttentionCode): number {
    return (this.attention()?.items ?? []).filter(item => item.reasons.some(r => r.code === code)).length;
  }

  toggleFilter(code: AttentionCode): void {
    this.attentionFilter.update(current => current === code ? '' : code);
  }

  async openTask(task: Task): Promise<void> {
    this.detailProject.set(this.projects().find(p => p.id === task.project_id) ?? null);
    const generation = ++this.detailGeneration;
    this.error.set('');
    this.creating.set(false);
    this.detail.set(null);
    try {
      const detail = await this.api.request<TaskDetail>(`/tasks/${task.id}`);
      if (generation !== this.detailGeneration) return;
      this.detail.set(detail);
      this.comment = '';
      this.draft = {
        title: detail.title, description: detail.description, status: detail.status,
        assignee_id: detail.assignee_id,
        due_at: detail.due_at ? detail.due_at.slice(0, 16) : '',
      };
    } catch (error) {
      if (generation === this.detailGeneration) this.fail(error);
    }
  }

  closeDetail(): void {
    ++this.detailGeneration;
    this.detail.set(null);
    this.detailProject.set(null);
    this.creating.set(false);
    this.comment = '';
  }

  createTask(): void {
    this.closeDetail();
    this.detailProject.set(this.project());
    this.draft = this.emptyDraft();
    this.creating.set(true);
  }

  async saveTask(): Promise<void> {
    const project = this.detailProject();
    if (!project || this.saving()) return;
    const userId = this.api.userId;
    const generation = this.detailGeneration;
    const existing = this.detail();
    this.saving.set(true);
    this.error.set('');
    try {
      const data = {
        ...this.draft,
        due_at: this.dueForSave(existing),
        ...(existing ? { version: existing.version } : {}),
      };
      const task = await this.api.request<Task>(existing ? `/tasks/${existing.id}` : `/projects/${project.id}/tasks`, existing ? 'PATCH' : 'POST', data);
      if (userId !== this.api.userId) return;
      if (!existing) this.projects.update(list => list.map(p => p.id === project.id ? { ...p, task_count: p.task_count + 1 } : p));
      if (this.project()?.id === project.id) this.tasks.update(list => existing ? list.map(t => t.id === task.id ? task : t) : [...list, task]);
      if (this.view() === 'attention') void this.refreshAttention();
      if (generation === this.detailGeneration) {
        // Saving fields must not erase a comment the user has not sent yet.
        this.detail.set({ ...task, comments: existing?.comments ?? [] });
        this.creating.set(false);
        this.draft = {
          title: task.title, description: task.description, status: task.status,
          assignee_id: task.assignee_id, due_at: task.due_at?.slice(0, 16) ?? '',
        };
        this.notice.set('Задача сохранена');
      }
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  async addComment(): Promise<void> {
    const task = this.detail();
    if (!task || this.saving()) return;
    const generation = this.detailGeneration;
    this.saving.set(true);
    this.error.set('');
    try {
      const comment = await this.api.request<Comment>(`/tasks/${task.id}/comments`, 'POST', { body: this.comment });
      if (generation !== this.detailGeneration) return;
      // Refresh only the discussion: fields may contain unsaved user edits.
      this.detail.update(current => current ? { ...current, comments: [...current.comments, comment] } : current);
      this.comment = '';
      this.notice.set('Комментарий добавлен');
      if (this.view() === 'attention') void this.refreshAttention();
    } catch (error) { if (generation === this.detailGeneration) this.fail(error); }
    finally { this.saving.set(false); }
  }

  /** Поле datetime-local хранит минуты; без правки срока отправляем исходное значение с секундами. */
  private dueForSave(existing: TaskDetail | null): string | null {
    if (!this.draft.due_at) return null;
    if (existing?.due_at && existing.due_at.slice(0, 16) === this.draft.due_at) return existing.due_at;
    return `${this.draft.due_at.slice(0, 16)}:00Z`;
  }

  plural(n: number, one: string, few: string, many: string): string {
    const m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    return m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? few : many;
  }

  statusLabel(status: string): string { return this.statuses.find(s => s.value === status)?.label ?? status; }
  userName(id: number | null): string { return this.context()?.users.find(u => u.id === id)?.name ?? 'Не назначен'; }
  private fail(error: unknown): void { this.error.set(error instanceof Error ? error.message : 'Не удалось выполнить запрос'); }
  private emptyDraft(): { title: string; description: string; status: TaskStatus; assignee_id: number | null; due_at: string } {
    return { title: '', description: '', status: 'open', assignee_id: null, due_at: '' };
  }
}
