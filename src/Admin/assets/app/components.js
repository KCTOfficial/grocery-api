/*!
 * Grocery Restable admin - reusable form components:
 * database connection form, permission grid, conditions editor and order rows.
 */
(function (GR, $) {
    'use strict';

    const { esc, icon } = GR.html;

    // ---------------------------------------------------------------- connection form (setup + settings)

    const engineUsable = (e) => e.supported && Object.values(e.clients).some((c) => c.installed);
    const defaultEngine = (engines) => Object.keys(engines).find((k) => engineUsable(engines[k])) || 'mysql';

    function clientOptions(engine, selected) {
        if (!engine) return '';
        const clients = Object.entries(engine.clients);
        const usable = clients.find(([k, c]) => k === selected && c.installed) ? selected : (clients.find(([, c]) => c.installed) || [''])[0];
        return clients.map(([k, c]) => `<option value="${esc(k)}" ${k === usable ? 'selected' : ''} ${c.installed ? '' : 'disabled'}>${esc(c.label)} - ${esc(c.extension)}${c.installed ? '' : ' (not installed)'}</option>`).join('');
    }

    function connectionFields(d, engines) {
        const engineLabel = (e) => esc(e.label) + (!e.supported ? ' (not supported yet)' : !engineUsable(e) ? ' (PHP extension missing)' : '');
        return `<div class="row g-2">
            <div class="col-md-4"><label class="form-label small mb-0">Database engine</label><select class="form-select form-select-sm" name="driver">
                ${Object.entries(engines).map(([k, e]) => `<option value="${esc(k)}" ${d.driver === k ? 'selected' : ''} ${engineUsable(e) ? '' : 'disabled'}>${engineLabel(e)}</option>`).join('')}</select></div>
            <div class="col-md-8"><label class="form-label small mb-0">PHP driver</label><select class="form-select form-select-sm" name="client" data-engines="${esc(JSON.stringify(engines))}">${clientOptions(engines[d.driver], d.client || 'pdo')}</select></div>
            <div class="col-md-8 gr-net"><label class="form-label small mb-0">Host</label><input class="form-control form-control-sm" name="host" value="${esc(d.host || '127.0.0.1')}"></div>
            <div class="col-md-4 gr-net"><label class="form-label small mb-0">Port</label><input class="form-control form-control-sm" name="port" value="${esc(d.port || '')}" placeholder="default"></div>
            <div class="col-md-12"><label class="form-label small mb-0 gr-db-label">Database</label><input class="form-control form-control-sm" name="database" value="${esc(d.database || '')}"></div>
            <div class="col-md-6 gr-net"><label class="form-label small mb-0">Username</label><input class="form-control form-control-sm" name="username" value="${esc(d.username || '')}" autocomplete="off"></div>
            <div class="col-md-6 gr-net"><label class="form-label small mb-0">Password</label><input type="password" class="form-control form-control-sm" name="password" value="" autocomplete="new-password" placeholder="${d.name ? 'leave empty to keep' : ''}"></div>
            <div class="col-md-6 gr-pg"><label class="form-label small mb-0">Schema</label><input class="form-control form-control-sm" name="schema" value="${esc(d.schema || 'public')}"></div>
            <div class="col-md-6 gr-pg"><label class="form-label small mb-0">SSL mode</label><select class="form-select form-select-sm" name="sslmode">
                ${['prefer', 'disable', 'require', 'verify-ca', 'verify-full'].map((m) => `<option ${d.sslmode === m ? 'selected' : ''}>${m}</option>`).join('')}</select></div>
        </div>`;
    }

    function bindConnectionFields($scope) {
        const $client = $scope.find('[name=client]');
        const engines = JSON.parse($client.attr('data-engines') || '{}');
        const sync = () => {
            const driver = $scope.find('[name=driver]').val();
            $scope.find('.gr-net').toggleClass('d-none', driver === 'sqlite');
            $scope.find('.gr-pg').toggleClass('d-none', driver !== 'pgsql');
            $scope.find('.gr-db-label').text(driver === 'sqlite' ? 'SQLite file path (absolute)' : 'Database name');
        };
        $scope.find('[name=driver]').on('change', function () {
            $client.html(clientOptions(engines[this.value], $client.val()));
            sync();
        });
        sync();
    }

    function connectionFieldValues($scope) {
        const values = {};
        ['driver', 'client', 'host', 'port', 'database', 'username', 'password', 'schema', 'sslmode'].forEach((k) => { values[k] = $scope.find(`[name=${k}]`).val(); });
        return values;
    }

    // ---------------------------------------------------------------- permission grid (role × operation)

    const ROLE_PATTERN = /^(\*|[A-Za-z_][A-Za-z0-9_-]{0,63})$/;

    function permissionRow(role, granted, ops) {
        return `<tr data-role="${esc(role)}"><td><b>${esc(role)}</b>${role === '*' ? ' <span class="text-muted small">(everybody)</span>' : ''}</td>
            ${ops.map((o) => `<td class="text-center"><input type="checkbox" class="form-check-input" data-op="${esc(o)}" ${granted.includes(o) ? 'checked' : ''}></td>`).join('')}
            <td>${['anon', 'authenticated'].includes(role) ? '' : `<a href="#" class="text-danger" data-remove-role title="Remove role">${icon('xmark')}</a>`}</td></tr>`;
    }

    function permissionGrid(permissions, ops, name = 'perm') {
        const roles = GR.util.uniq(['anon', 'authenticated', ...Object.keys(permissions || {})]);
        return `<table class="gr-table gr-perm-grid" data-name="${esc(name)}"><thead><tr><th>Role</th>${ops.map((o) => `<th class="text-center">${esc(o)}</th>`).join('')}<th></th></tr></thead><tbody>
            ${roles.map((r) => permissionRow(r, (permissions || {})[r] || [], ops)).join('')}</tbody>
            <tfoot><tr><td colspan="${ops.length + 2}"><div class="input-group input-group-sm" style="max-width:320px"><input class="form-control" placeholder="role name (or * for everybody)" data-new-role data-no-dirty>
            <button class="btn btn-outline-secondary" type="button" data-add-role>${icon('plus')} Add role</button></div></td></tr></tfoot></table>`;
    }

    function bindPermissionGrid($scope, ops) {
        $scope.on('click', '[data-add-role]', function () {
            const $grid = $(this).closest('table');
            const role = $grid.find('[data-new-role]').val().trim();
            if (!ROLE_PATTERN.test(role) || $grid.find(`tr[data-role="${CSS.escape(role)}"]`).length) {
                GR.ui.toast('Invalid or duplicate role name', 'warning');
                return;
            }
            $grid.find('tbody').append(permissionRow(role, [], ops));
            $grid.find('[data-new-role]').val('');
        });
        $scope.on('click', '[data-remove-role]', function (e) {
            e.preventDefault();
            $(this).closest('tr').remove();
        });
    }

    function readPermissionGrid($grid) {
        const result = {};
        $grid.find('tbody tr[data-role]').each(function () {
            result[this.dataset.role] = $(this).find('[data-op]:checked').map(function () { return this.dataset.op; }).get();
        });
        return result;
    }

    // ---------------------------------------------------------------- conditions & order (where / order_by)

    const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'in', 'not in', 'is null', 'is not null', 'like', 'not like'];
    const options = (list, selected) => list.map((x) => `<option ${x === selected ? 'selected' : ''}>${esc(x)}</option>`).join('');

    function conditionRow(cols, c, ro) {
        const dis = ro ? 'disabled' : '';
        const value = Array.isArray(c.value) ? c.value.join(', ') : (c.value ?? '');
        return `<div class="gr-cond-row d-flex gap-1 mb-1">
            <select class="form-select form-select-sm w-auto" data-k="boolean" ${dis}><option value="and" ${c.boolean !== 'or' ? 'selected' : ''}>AND</option><option value="or" ${c.boolean === 'or' ? 'selected' : ''}>OR</option></select>
            <select class="form-select form-select-sm" data-k="column" ${dis}>${options(cols, c.column)}</select>
            <select class="form-select form-select-sm w-auto" data-k="operator" ${dis}>${options(OPERATORS, c.operator || '=')}</select>
            <input class="form-control form-control-sm" data-k="value" value="${esc(value)}" placeholder="value (in: a, b, c · like: %text%)" ${dis}>
            ${ro ? '' : `<button type="button" class="btn btn-sm btn-outline-danger" data-cond-del title="Remove">${icon('xmark')}</button>`}</div>`;
    }

    function conditionsEditor(conditions, cols, ro) {
        return `<div class="gr-conds">${(conditions || []).map((c) => conditionRow(cols, c, ro)).join('')}</div>`
            + (ro ? '' : `<button type="button" class="btn btn-sm btn-outline-secondary" data-cond-add>${icon('plus')} Condition</button>`);
    }

    function bindConditionsEditor($scope, cols) {
        $scope.on('click', '[data-cond-add]', () => $scope.find('.gr-conds').append(conditionRow(cols, { column: cols[0], operator: '=' }, false)));
        $scope.on('click', '[data-cond-del]', function () { $(this).closest('.gr-cond-row').remove(); });
    }

    function readConditions($scope) {
        return $scope.find('.gr-cond-row').map(function () {
            const $r = $(this);
            const operator = $r.find('[data-k=operator]').val();
            let value = $r.find('[data-k=value]').val();
            if (['in', 'not in'].includes(operator)) value = value.split(',').map((s) => s.trim()).filter((s) => s !== '');
            if (['is null', 'is not null'].includes(operator)) value = null;
            return { boolean: $r.find('[data-k=boolean]').val(), column: $r.find('[data-k=column]').val(), operator, value };
        }).get();
    }

    const orderRow = (cols, column, dir, ro) => `<div class="gr-order-row d-flex gap-1 mb-1"><span class="small text-muted align-self-center" style="width:70px">ORDER BY</span>
        <select class="form-select form-select-sm" data-k="column" ${ro ? 'disabled' : ''}>${options(cols, column)}</select>
        <select class="form-select form-select-sm w-auto" data-k="dir" ${ro ? 'disabled' : ''}>${options(['asc', 'desc'], dir)}</select>
        ${ro ? '' : `<button type="button" class="btn btn-sm btn-outline-danger" data-order-del title="Remove">${icon('xmark')}</button>`}</div>`;

    GR.components = {
        defaultEngine, connectionFields, bindConnectionFields, connectionFieldValues,
        permissionGrid, bindPermissionGrid, readPermissionGrid,
        conditionsEditor, bindConditionsEditor, readConditions, orderRow,
    };
})(window.GR = window.GR || {}, jQuery);
