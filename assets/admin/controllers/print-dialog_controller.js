import { Controller } from '@hotwired/stimulus';

const AGENT_TIMEOUT_MS = 3000;

function toast(message, type) {
    let container = document.getElementById('zangra-barcode-print-toasts');
    if (!container) {
        container = document.createElement('div');
        container.id = 'zangra-barcode-print-toasts';
        container.className = 'zangra-barcode-print-toasts';
        document.body.appendChild(container);
    }

    const alert = document.createElement('div');
    alert.className = `alert alert-${type} alert-dismissible shadow`;
    alert.setAttribute('role', 'alert');
    alert.textContent = message;

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close';
    close.setAttribute('aria-label', 'Close');
    close.addEventListener('click', () => alert.remove());
    alert.appendChild(close);

    container.appendChild(alert);
    window.setTimeout(() => alert.remove(), 'success' === type ? 5000 : 12000);
}

function fetchWithTimeout(url, options, timeout) {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), timeout);

    return fetch(url, { ...options, signal: controller.signal }).finally(() => window.clearTimeout(timer));
}

/*
 * Print dialog of the "Print label" button. The markup (dialog, selects, item rows, field templates) is rendered by
 * templates/admin/print_button.html.twig; this controller only holds the behavior.
 *
 * Single mode: the variables of the selected label template are editable fields.
 * Batch mode (rows of printable items): one label job per checked row, built from the row only:
 * {...row.variables, code, <ean13 variable key>: ean}. Other template variables use their configured default.
 */
export default class extends Controller {
    static targets = [
        'dialog', 'templateSelect', 'printerSelect', 'variables', 'quantity', 'status', 'printButton',
        'selectAll', 'row', 'fieldTemplate', 'iconsTemplate', 'iconTemplate',
    ];

    static values = {
        renderUrl: String,
        batch: Boolean,
        templates: Array,
        icons: Array,
        defaults: Object,
        messages: Object,
    };

    connect() {
        this.inputs = {};
        if (!this.batchValue && this.hasVariablesTarget) {
            this.renderVariables();
        }
    }

    open() {
        this.clearErrors();
        if (!this.dialogTarget.open) {
            this.dialogTarget.showModal();
        }
    }

    close() {
        this.dialogTarget.close();
    }

    closeOnBackdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    submitOnEnter(event) {
        if (event.target.closest('button, select, textarea') || !this.hasPrintButtonTarget) {
            return;
        }

        event.preventDefault();
        this.submit();
    }

    currentTemplate() {
        const code = this.hasTemplateSelectTarget ? this.templateSelectTarget.value : (this.templatesValue[0] || {}).code;

        return this.templatesValue.find((template) => template.code === code) || null;
    }

    // Single mode: one field per variable of the selected template.
    renderVariables() {
        if (!this.hasVariablesTarget) {
            return;
        }

        this.variablesTarget.textContent = '';
        this.inputs = {};
        const template = this.currentTemplate();
        if (null === template) {
            return;
        }

        template.variables.forEach((variable) => {
            const provided = Object.prototype.hasOwnProperty.call(this.defaultsValue, variable.key) ? this.defaultsValue[variable.key] : variable.default;
            const field = 'icons' === variable.type ? this.iconsField(provided) : this.textField(variable, provided);

            field.element.setAttribute('data-field', variable.key);
            field.element.querySelector('[data-role="label"]').textContent = variable.label + (variable.required ? ' *' : '');
            this.inputs[variable.key] = field.getValue;
            this.variablesTarget.appendChild(field.element);
        });
    }

    textField(variable, provided) {
        const element = this.fieldTemplateTarget.content.firstElementChild.cloneNode(true);
        const input = element.querySelector('[data-role="input"]');
        input.value = null === provided || undefined === provided ? '' : provided;
        if ('ean13' === variable.type) {
            input.setAttribute('inputmode', 'numeric');
            input.setAttribute('maxlength', '13');
        }

        return { element, getValue: () => input.value };
    }

    iconsField(provided) {
        const element = this.iconsTemplateTarget.content.firstElementChild.cloneNode(true);
        const container = element.querySelector('[data-role="input"]');
        const selected = Array.isArray(provided) ? provided : String(provided || '').split(',').map((key) => key.trim().toLowerCase());

        this.iconsValue.forEach((icon) => {
            const option = this.iconTemplateTarget.content.firstElementChild.cloneNode(true);
            const checkbox = option.querySelector('[data-role="input"]');
            checkbox.value = icon;
            checkbox.checked = selected.includes(icon);
            option.querySelector('[data-role="label"]').textContent = icon.toUpperCase();
            container.appendChild(option);
        });

        return {
            element,
            getValue: () => Array.from(container.querySelectorAll('input:checked')).map((checkbox) => checkbox.value),
        };
    }

    // Batch mode: tri-state select all checkbox.
    toggleAll() {
        this.rowTargets.forEach((row) => {
            row.querySelector('[data-role="select"]').checked = this.selectAllTarget.checked;
        });
    }

    syncSelectAll() {
        const checked = this.rowTargets.filter((row) => row.querySelector('[data-role="select"]').checked).length;
        this.selectAllTarget.checked = checked === this.rowTargets.length;
        this.selectAllTarget.indeterminate = 0 !== checked && checked !== this.rowTargets.length;
    }

    clearErrors() {
        this.dialogTarget.querySelectorAll('.is-invalid').forEach((node) => node.classList.remove('is-invalid'));
        this.dialogTarget.querySelectorAll('.invalid-feedback').forEach((node) => {
            node.textContent = '';
            node.style.display = '';
        });
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = '';
        }
    }

    showErrors(errors, itemCode) {
        const general = [];
        Object.keys(errors).forEach((key) => {
            const group = this.dialogTarget.querySelector(`[data-field="${CSS.escape(key)}"]`);
            const feedback = group ? group.querySelector('.invalid-feedback') : null;
            if (feedback) {
                group.querySelector('input, select').classList.add('is-invalid');
                feedback.textContent = errors[key];
                feedback.style.display = 'block';
            } else {
                general.push((itemCode ? `${itemCode}: ` : '') + errors[key]);
            }
        });
        if (general.length) {
            toast(general.join(' '), 'danger');
        }
    }

    setBusy(busy) {
        this.printButtonTarget.disabled = busy;
        this.statusTarget.textContent = busy ? this.messagesValue.sending : '';
    }

    // The key of the template variable of type ean13, or null when the template has none.
    eanKey(template) {
        const variable = template.variables.find((candidate) => 'ean13' === candidate.type);

        return variable ? variable.key : null;
    }

    // The jobs to send: a single one, or one per checked row in batch mode.
    payloads() {
        const template = this.currentTemplate();
        const printer = this.printerSelectTarget.value;

        if (!this.batchValue) {
            const variables = {};
            Object.keys(this.inputs).forEach((key) => { variables[key] = this.inputs[key](); });

            return [{ template: template.code, printer, quantity: parseInt(this.quantityTarget.value, 10) || 0, variables }];
        }

        const eanKey = this.eanKey(template);

        return this.rowTargets
            .filter((row) => row.querySelector('[data-role="select"]').checked)
            .map((row) => {
                const variables = { ...JSON.parse(row.dataset.variables || '{}'), code: row.dataset.code };
                if (null !== eanKey) {
                    variables[eanKey] = row.dataset.ean;
                }

                return {
                    template: template.code,
                    printer,
                    quantity: parseInt(row.querySelector('[data-role="quantity"]').value, 10) || 0,
                    variables,
                };
            });
    }

    submit() {
        const messages = this.messagesValue;
        const jobs = this.payloads();
        this.clearErrors();
        if (0 === jobs.length) {
            toast(messages.none_selected, 'warning');

            return;
        }

        this.setBusy(true);
        // Jobs are sent one after the other and stop at the first failure.
        jobs.reduce((previous, job) => previous.then((printed) => {
            if (!printed) {
                return false;
            }

            return this.send(job, this.batchValue ? job.variables.code : null);
        }), Promise.resolve(true)).then((printed) => {
            if (printed) {
                toast(jobs.length > 1 ? messages.success_many.replace('%count%', String(jobs.length)) : messages.success, 'success');
                this.close();
            }
        }).catch((error) => {
            toast(error.message || messages.request_failed, 'danger');
        }).finally(() => {
            this.setBusy(false);
        });
    }

    // Resolves to true when the job was printed, false when the server reported field errors.
    send(payload, itemCode) {
        const messages = this.messagesValue;

        return fetch(this.renderUrlValue, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload),
        }).then((response) => response.json().catch(() => ({})).then((data) => ({ status: response.status, data }))).then((result) => {
            if (422 === result.status && result.data.errors) {
                this.showErrors(result.data.errors, itemCode);

                return false;
            }
            if (200 !== result.status) {
                throw new Error(result.data.error || messages.request_failed);
            }
            if ('local_agent' === result.data.transport) {
                return this.sendToAgent(result.data);
            }

            return true;
        }).catch((error) => {
            if (itemCode) {
                error.message = `${itemCode}: ${error.message || messages.request_failed}`;
            }

            throw error;
        });
    }

    sendToAgent(job) {
        const messages = this.messagesValue;
        const agent = job.agentUrl.replace(/\/+$/, '');

        return fetchWithTimeout(`${agent}/health`, { method: 'GET' }, AGENT_TIMEOUT_MS).catch(() => {
            throw new Error(messages.agent_not_running);
        }).then(() => fetchWithTimeout(`${agent}/print`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ host: job.host, port: job.port, zpl: job.zpl }),
        }, AGENT_TIMEOUT_MS * 4).catch(() => {
            throw new Error(messages.agent_not_running);
        })).then((response) => {
            if (204 === response.status || response.ok) {
                return true;
            }
            if (403 === response.status) {
                throw new Error(messages.agent_refused);
            }
            if (502 === response.status) {
                throw new Error(messages.agent_printer_unreachable);
            }

            throw new Error(messages.request_failed);
        });
    }
}
