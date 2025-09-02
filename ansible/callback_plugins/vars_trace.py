# callback_plugins/vars_trace.py
from __future__ import annotations
from ansible.plugins.callback import CallbackBase
import copy, os, re
from ansible import constants as C   # ← added for color constants
try:
    import yaml
except Exception:
    yaml = None  # we'll fall back to repr if PyYAML isn't available

DOCUMENTATION = r'''
callback: vars_trace
short_description: Show per-task variable changes (register/set_fact/facts)
description:
  - Prints readable diffs for variables that change due to register:, set_fact:, or modules returning ansible_facts.
options:
  filter_regex:
    description: Regex to include only matching variable names (e.g. ^(app_|env_|d_|v_|f_|l_))
    env:
      - name: VARS_TRACE_FILTER
    default: ''
  redact_regex:
    description: Regex of keys to redact (e.g. (?i)(password|token|secret|api_key))
    env:
      - name: VARS_TRACE_REDACT
    default: '(?i)(password|token|secret|api_key)'
  min_verbosity:
    description: Minimum Ansible -v level required to print (0..4)
    env:
      - name: VARS_TRACE_VERBOSITY
    default: '1'
type: aggregate
'''

class CallbackModule(CallbackBase):
    CALLBACK_VERSION = 2.0
    CALLBACK_TYPE = 'aggregate'
    CALLBACK_NAME = 'vars_trace'
    CALLBACK_NEEDS_WHITELIST = False

    def __init__(self):
        super().__init__()
        self._host_snapshots = {}  # host -> { varname: value }
        self._task_name = None
        self._task_path = None
        self.filter_re = self._compile_env('VARS_TRACE_FILTER', default='')
        self.redact_re = self._compile_env('VARS_TRACE_REDACT', default='(?i)(password|token|secret|api_key)')
        self.min_verbosity = int(os.getenv('VARS_TRACE_VERBOSITY', '1'))

    def _compile_env(self, key, default=''):
        val = os.getenv(key, default)
        return re.compile(val) if val else None

    def v2_playbook_on_task_start(self, task, is_conditional):
        self._task_name = task.get_name().strip() or task.action
        self._task_path = task.get_path()

    def _snap(self, host):
        return self._host_snapshots.setdefault(host, {})

    def _allow(self, name):
        return (self.filter_re.search(name) if self.filter_re else True)

    def _redact(self, name, value):
        if self.redact_re and self.redact_re.search(name):
            return '***REDACTED***'
        return value

    def _fmt(self, obj):
        if yaml:
            try:
                return yaml.safe_dump(obj, sort_keys=True, default_flow_style=False).rstrip()
            except Exception:
                pass
        # fallback
        return repr(obj)

    def v2_runner_on_ok(self, result):
        disp = self._display
        if disp.verbosity < self.min_verbosity:
            return

        host = result._host.get_name()
        prev = self._snap(host)
        cur = copy.deepcopy(prev)

        data = result._result or {}
        changed = {}

        # 1) set_fact or modules returning facts
        for k, v in (data.get('ansible_facts') or {}).items():
            if self._allow(k):
                vv = self._redact(k, v)
                if k not in prev or prev.get(k) != vv:
                    changed[k] = {'before': prev.get(k, '<UNSET>'), 'after': vv}
                cur[k] = vv

        # 2) register: <name>
        reg = getattr(result._task, '_register', None)
        if reg and self._allow(reg):
            rv = self._redact(reg, data)
            if reg not in prev or prev.get(reg) != rv:
                changed[reg] = {'before': prev.get(reg, '<UNSET>'), 'after': rv}
            cur[reg] = rv

        # Save snapshot
        self._host_snapshots[host] = cur

        if changed:
            hdr = f"TASK [{self._task_name}] on {host}"
            if self._task_path:
                hdr += f"  ({self._task_path})"
            disp.banner(hdr)
            # Pretty, line-by-line before/after
            for name in sorted(changed.keys()):
                before = self._fmt(changed[name]['before'])
                after  = self._fmt(changed[name]['after'])
                # ← this line now colorized in green
                disp.display(f"vars_trace: {name}  (changed)", color=C.COLOR_OK)
                disp.display("  before:\n" + "\n".join("    " + ln for ln in before.splitlines() or ["<empty>"]))
                disp.display("  after:\n"  + "\n".join("    " + ln for ln in after.splitlines()  or ["<empty>"]))

