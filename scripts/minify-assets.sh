#!/bin/bash

# ============================================================
# Скрипт минификации CSS и JS для ОГАС
# ============================================================
# Использование: ./scripts/minify-assets.sh
# ============================================================

set -e

echo "🚀 Начинаем минификацию ассетов..."

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Директории
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CSS_DIR="$PROJECT_ROOT/public/css"
JS_DIR="$PROJECT_ROOT/public/js"

# Проверка наличия Node.js
if ! command -v node &> /dev/null; then
    echo -e "${RED}❌ Node.js не установлен!${NC}"
    echo "Установите Node.js: https://nodejs.org/"
    exit 1
fi

# Проверка наличия npm
if ! command -v npm &> /dev/null; then
    echo -e "${RED}❌ npm не установлен!${NC}"
    exit 1
fi

echo -e "${GREEN}✓${NC} Node.js и npm найдены"

# ============================================================
# Установка зависимостей (если нужно)
# ============================================================

echo ""
echo "📦 Проверяем зависимости..."

# Проверяем наличие csso-cli
if ! command -v csso &> /dev/null; then
    echo -e "${YELLOW}⚠${NC}  csso-cli не установлен. Устанавливаем..."
    npm install -g csso-cli
    echo -e "${GREEN}✓${NC} csso-cli установлен"
else
    echo -e "${GREEN}✓${NC} csso-cli найден"
fi

# Проверяем наличие terser
if ! command -v terser &> /dev/null; then
    echo -e "${YELLOW}⚠${NC}  terser не установлен. Устанавливаем..."
    npm install -g terser
    echo -e "${GREEN}✓${NC} terser установлен"
else
    echo -e "${GREEN}✓${NC} terser найден"
fi

# ============================================================
# Минификация CSS
# ============================================================

echo ""
echo "🎨 Минифицируем CSS..."

CSS_COUNT=0
CSS_ORIGINAL_SIZE=0
CSS_MINIFIED_SIZE=0

# Найти все CSS файлы (кроме уже минифицированных)
while IFS= read -r -d '' file; do
    # Пропускаем уже минифицированные файлы
    if [[ "$file" == *.min.css ]]; then
        continue
    fi
    
    # Получаем имя файла без расширения
    filename=$(basename "$file" .css)
    dirname=$(dirname "$file")
    minified="$dirname/$filename.min.css"
    
    # Получаем размеры
    original_size=$(stat -c%s "$file" 2>/dev/null || stat -f%z "$file" 2>/dev/null)
    
    # Минифицируем
    if csso "$file" -o "$minified"; then
        minified_size=$(stat -c%s "$minified" 2>/dev/null || stat -f%z "$minified" 2>/dev/null)
        savings=$((original_size - minified_size))
        savings_percent=$((savings * 100 / original_size))
        
        echo -e "  ${GREEN}✓${NC} $filename.css → $filename.min.css (экономия: ${savings_percent}%)"
        
        CSS_COUNT=$((CSS_COUNT + 1))
        CSS_ORIGINAL_SIZE=$((CSS_ORIGINAL_SIZE + original_size))
        CSS_MINIFIED_SIZE=$((CSS_MINIFIED_SIZE + minified_size))
    else
        echo -e "  ${RED}✗${NC} Ошибка при минификации $file"
    fi
done < <(find "$CSS_DIR" -type f -name "*.css" -print0)

# ============================================================
# Минификация JavaScript
# ============================================================

echo ""
echo "⚡ Минифицируем JavaScript..."

JS_COUNT=0
JS_ORIGINAL_SIZE=0
JS_MINIFIED_SIZE=0

# Найти все JS файлы (кроме уже минифицированных)
while IFS= read -r -d '' file; do
    # Пропускаем уже минифицированные файлы
    if [[ "$file" == *.min.js ]]; then
        continue
    fi
    
    # Получаем имя файла без расширения
    filename=$(basename "$file" .js)
    dirname=$(dirname "$file")
    minified="$dirname/$filename.min.js"
    
    # Получаем размеры
    original_size=$(stat -c%s "$file" 2>/dev/null || stat -f%z "$file" 2>/dev/null)
    
    # Минифицируем с сохранением важных комментариев
    if terser "$file" -o "$minified" --compress --mangle --comments "/^!/" 2>/dev/null; then
        minified_size=$(stat -c%s "$minified" 2>/dev/null || stat -f%z "$minified" 2>/dev/null)
        savings=$((original_size - minified_size))
        savings_percent=$((savings * 100 / original_size))
        
        echo -e "  ${GREEN}✓${NC} $filename.js → $filename.min.js (экономия: ${savings_percent}%)"
        
        JS_COUNT=$((JS_COUNT + 1))
        JS_ORIGINAL_SIZE=$((JS_ORIGINAL_SIZE + original_size))
        JS_MINIFIED_SIZE=$((JS_MINIFIED_SIZE + minified_size))
    else
        echo -e "  ${RED}✗${NC} Ошибка при минификации $file"
    fi
done < <(find "$JS_DIR" -type f -name "*.js" -print0)

# ============================================================
# Статистика
# ============================================================

echo ""
echo "📊 Статистика минификации:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

if [ $CSS_COUNT -gt 0 ]; then
    css_total_savings=$((CSS_ORIGINAL_SIZE - CSS_MINIFIED_SIZE))
    css_percent=$((css_total_savings * 100 / CSS_ORIGINAL_SIZE))
    echo -e "CSS:  $CSS_COUNT файлов"
    echo -e "      Было: $(numfmt --to=iec --format='%.1f' $CSS_ORIGINAL_SIZE 2>/dev/null || echo "${CSS_ORIGINAL_SIZE} bytes")"
    echo -e "      Стало: $(numfmt --to=iec --format='%.1f' $CSS_MINIFIED_SIZE 2>/dev/null || echo "${CSS_MINIFIED_SIZE} bytes")"
    echo -e "      ${GREEN}Экономия: ${css_percent}%${NC}"
fi

if [ $JS_COUNT -gt 0 ]; then
    js_total_savings=$((JS_ORIGINAL_SIZE - JS_MINIFIED_SIZE))
    js_percent=$((js_total_savings * 100 / JS_ORIGINAL_SIZE))
    echo -e "JS:   $JS_COUNT файлов"
    echo -e "      Было: $(numfmt --to=iec --format='%.1f' $JS_ORIGINAL_SIZE 2>/dev/null || echo "${JS_ORIGINAL_SIZE} bytes")"
    echo -e "      Стало: $(numfmt --to=iec --format='%.1f' $JS_MINIFIED_SIZE 2>/dev/null || echo "${JS_MINIFIED_SIZE} bytes")"
    echo -e "      ${GREEN}Экономия: ${js_percent}%${NC}"
fi

total_original=$((CSS_ORIGINAL_SIZE + JS_ORIGINAL_SIZE))
total_minified=$((CSS_MINIFIED_SIZE + JS_MINIFIED_SIZE))
total_savings=$((total_original - total_minified))

if [ $total_original -gt 0 ]; then
    total_percent=$((total_savings * 100 / total_original))
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo -e "ИТОГО: $(numfmt --to=iec --format='%.1f' $total_original 2>/dev/null || echo "${total_original} bytes") → $(numfmt --to=iec --format='%.1f' $total_minified 2>/dev/null || echo "${total_minified} bytes")"
    echo -e "       ${GREEN}Общая экономия: ${total_percent}%${NC}"
fi

echo ""
echo -e "${GREEN}✅ Минификация завершена!${NC}"

# ============================================================
# Дополнительные рекомендации
# ============================================================

echo ""
echo "💡 Рекомендации:"
echo "  1. Обновите ссылки в HTML для использования .min.css и .min.js"
echo "  2. Настройте .htaccess для gzip сжатия"
echo "  3. Добавьте версионирование файлов (style.min.css?v=1.0)"
echo "  4. Используйте CDN для статических файлов"
echo ""





