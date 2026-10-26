const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname,'../rrhh.js'),'latin1');
const start = source.indexOf('function buildMensualLocalTableHtml(');
const end = source.indexOf('function buildMensualLocalGroupsHtml(',start);
const context = {escapeHtml: s=>String(s).replaceAll('<','&lt;'),moneyAR: n=>Number(n).toFixed(2)};
vm.createContext(context);
vm.runInContext(source.slice(start,end),context);
const html = context.buildMensualLocalTableHtml([
 {nombre:'<Ana>',legajo:1,transferencia:10,efectivo:20,creditos:5,monto:35,pagos_haberes:{efectivo:true,transferencia:false}},
 {nombre:'Juan',legajo:2,transferencia:30,efectivo:40,creditos:0,monto:70,pagos_haberes:{efectivo:false,transferencia:true}}
]);
assert.equal((html.match(/<th>/g)||[]).length,6);
assert.equal((html.match(/kolrrhh-haber-pagado/g)||[]).length,2);
assert(html.includes('&lt;Ana>'));
const footer = html.slice(html.indexOf('<tfoot>'));
for (const total of ['40.00','60.00','5.00','105.00']) assert(footer.includes(total));
assert(!html.includes('<th>Monto</th>'));
console.log('PASS: five columns, paid cells, escaped names and all four totals');
