export type FileUploadSource = "input" | "drop";

export interface FileUploadFilesDetail {
  files: FileList;
  source: FileUploadSource;
  input: HTMLInputElement;
}

const controllers = new WeakMap<HTMLElement, FileUploadController>();

function owns(root: HTMLElement, target: Element): boolean {
  return target.closest<HTMLElement>("[data-shlz-file-upload]") === root;
}

function resolveInput(root: HTMLElement): HTMLInputElement {
  if (!root.matches("[data-shlz-file-upload]"))
    throw new TypeError("File Upload requires a [data-shlz-file-upload] root.");
  const inputs = [
    ...root.querySelectorAll<HTMLInputElement>('input[type="file"]'),
  ].filter((input) => owns(root, input));
  if (inputs.length !== 1)
    throw new TypeError(
      "File Upload requires exactly one root-owned input[type=file].",
    );
  return inputs[0];
}

function hasFiles(transfer: DataTransfer | null): boolean {
  if (!transfer) return false;
  if ([...transfer.items].some((item) => item.kind === "file")) return true;
  return [...transfer.types].includes("Files");
}

export class FileUploadController {
  readonly root: HTMLElement;
  readonly input: HTMLInputElement;
  readonly #abort = new AbortController();
  #destroyed = false;
  #dragDepth = 0;

  constructor(root: HTMLElement) {
    this.root = root;
    this.input = resolveInput(root);
    controllers.get(root)?.destroy();
    controllers.set(root, this);

    this.input.addEventListener(
      "change",
      () => {
        if (!this.input.disabled && this.input.files)
          this.#emit(this.input.files, "input");
      },
      { signal: this.#abort.signal },
    );
    root.addEventListener(
      "dragenter",
      (event) => {
        if (this.#unavailable(event) || !hasFiles(event.dataTransfer)) return;
        event.preventDefault();
        this.#dragDepth += 1;
        root.dataset.dragActive = "true";
      },
      { signal: this.#abort.signal },
    );
    root.addEventListener(
      "dragover",
      (event) => {
        if (this.#unavailable(event) || !hasFiles(event.dataTransfer)) return;
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = "copy";
      },
      { signal: this.#abort.signal },
    );
    root.addEventListener(
      "dragleave",
      (event) => {
        if (this.#unavailable(event) || !hasFiles(event.dataTransfer)) return;
        this.#dragDepth = Math.max(0, this.#dragDepth - 1);
        if (this.#dragDepth === 0) delete root.dataset.dragActive;
      },
      { signal: this.#abort.signal },
    );
    root.addEventListener(
      "drop",
      (event) => {
        if (
          this.#destroyed ||
          !owns(this.root, event.target as Element) ||
          !hasFiles(event.dataTransfer)
        )
          return;
        event.preventDefault();
        this.#clearDrag();
        if (this.input.disabled) return;
        const files = event.dataTransfer?.files;
        if (files?.length) this.#emit(files, "drop");
      },
      { signal: this.#abort.signal },
    );
  }

  destroy(): void {
    if (this.#destroyed) return;
    this.#destroyed = true;
    this.#abort.abort();
    this.#clearDrag();
    if (controllers.get(this.root) === this) controllers.delete(this.root);
  }

  #unavailable(event: DragEvent): boolean {
    return (
      this.#destroyed ||
      this.input.disabled ||
      !owns(this.root, event.target as Element)
    );
  }

  #clearDrag(): void {
    this.#dragDepth = 0;
    delete this.root.dataset.dragActive;
  }

  #emit(files: FileList, source: FileUploadSource): void {
    this.root.dispatchEvent(
      new CustomEvent<FileUploadFilesDetail>("shlz:file-upload-files", {
        bubbles: true,
        detail: { files, source, input: this.input },
      }),
    );
  }
}

export function enhanceFileUploads(
  scope: ParentNode = document,
): FileUploadController[] {
  const roots: HTMLElement[] = [];
  if (scope instanceof HTMLElement && scope.matches("[data-shlz-file-upload]"))
    roots.push(scope);
  roots.push(...scope.querySelectorAll<HTMLElement>("[data-shlz-file-upload]"));
  return roots.map(
    (root) => controllers.get(root) ?? new FileUploadController(root),
  );
}
