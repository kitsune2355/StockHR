var XLSX = require('C:/AppServ/www/stock_hl/assets/js/xlsx.mini.min.js');
var ws = XLSX.utils.aoa_to_sheet([["ชื่อ","หน่วยงาน"],["สมชาย","ฝ่าย A\nฝ่าย B\nฝ่าย C"]]);
ws['B2'].s = { alignment: { wrapText:true, vertical:'top' } };
ws['!rows'] = [ {}, { hpt: 45 } ];
var wb = XLSX.utils.book_new(); XLSX.utils.book_append_sheet(wb, ws, "S");
var buf = XLSX.write(wb, { bookType:'xlsx', type:'array', bookSST:true });
var fs = require('fs'); fs.writeFileSync('t.xlsx', Buffer.from(buf));
// unzip sheet xml + styles xml
var cp = require('child_process');
console.log("--- sheet1.xml ---");
try { console.log(cp.execSync('unzip -p t.xlsx xl/worksheets/sheet1.xml').toString().slice(0,1200)); } catch(e){ console.log('no unzip', e.message); }
console.log("--- styles.xml ---");
try { console.log(cp.execSync('unzip -p t.xlsx xl/styles.xml').toString().slice(0,1200)); } catch(e){ console.log(e.message); }
