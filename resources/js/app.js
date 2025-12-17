import './bootstrap';
import Chart from 'chart.js/auto';
import {
    CandlestickController,
    CandlestickElement,
    OhlcController,
    OhlcElement,
} from 'chartjs-chart-financial';
import zoomPlugin from 'chartjs-plugin-zoom';
import 'chartjs-adapter-date-fns';

// Регистрация контроллеров/элементов для свечных/ohlc графиков (chartjs-chart-financial 0.2.x)
Chart.register(
    CandlestickController,
    CandlestickElement,
    OhlcController,
    OhlcElement,
    zoomPlugin
);


document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('securities-app');
    if (!root) return;

    const apiBase = root.dataset.apiBase;
    const tickers = JSON.parse(root.dataset.tickers ?? '[]');
    const intervals = JSON.parse(root.dataset.intervals ?? '[]');
    const defaultTicker = root.dataset.defaultTicker;
    const defaultInterval = Number(root.dataset.defaultInterval ?? 60);
    const detailsBase = root.dataset.detailsBase;

    const tickerSelect = document.getElementById('ticker-select');
    const intervalSelect = document.getElementById('interval-select');
    const chartCanvas = document.getElementById('main-chart');
    const switchToLine = document.getElementById('switch-to-line');
    const switchToCandle = document.getElementById('switch-to-candle');
    const currentPriceEl = document.getElementById('current-price');
    const detailsLink = document.getElementById('security-details-link');
    const excelLink = document.getElementById('download-excel-link');
    const pdfLink = document.getElementById('download-pdf-link');

    let currentMode = 'line'; // 'line' | 'candle'
    let chartInstance = null;
    let allPoints = []; // Все загруженные точки данных
    let minDate = null; // Минимальная дата реальных данных
    let maxDate = null; // Максимальная дата реальных данных (последняя свеча)
    let axisMaxDate = null; // Максимальная дата оси (на 2 интервала правее последней свечи)
    let priceIntervalId = null; // Интервал автообновления текущей цены
    let chartIntervalId = null; // Интервал автообновления данных графика

    tickers.forEach((t) => {
        const opt = document.createElement('option');
        opt.value = t;
        opt.textContent = t;
        tickerSelect.appendChild(opt);
    });
    tickerSelect.value = defaultTicker;

    // Создаем кнопки интервалов
    const intervalButtonsContainer = document.getElementById('interval-buttons');
    intervals.forEach((i) => {
        // Добавляем в скрытый select для совместимости
        const opt = document.createElement('option');
        opt.value = i.value;
        opt.textContent = i.label;
        intervalSelect.appendChild(opt);

        // Создаем кнопку
        const button = document.createElement('button');
        button.type = 'button';
        button.value = i.value;
        button.textContent = i.label;
        button.className = 'px-4 py-2 text-sm border rounded transition-colors';

        // Устанавливаем активный класс для выбранного интервала
        if (i.value === defaultInterval) {
            button.classList.add('bg-blue-600', 'text-white', 'border-blue-600');
        } else {
            button.classList.add('bg-white', 'text-gray-700', 'border-gray-300', 'hover:bg-gray-50');
        }

        // Обработчик клика
        button.addEventListener('click', () => {
            // Убираем активный класс со всех кнопок
            intervalButtonsContainer.querySelectorAll('button').forEach(btn => {
                btn.classList.remove('bg-blue-600', 'text-white', 'border-blue-600');
                btn.classList.add('bg-white', 'text-gray-700', 'border-gray-300', 'hover:bg-gray-50');
            });

            // Добавляем активный класс к выбранной кнопке
            button.classList.remove('bg-white', 'text-gray-700', 'border-gray-300', 'hover:bg-gray-50');
            button.classList.add('bg-blue-600', 'text-white', 'border-blue-600');

            // Обновляем значение интервала и перезагружаем график
            intervalSelect.value = i.value;
            intervalSelect.dispatchEvent(new Event('change'));
        });

        intervalButtonsContainer.appendChild(button);
    });
    intervalSelect.value = defaultInterval;

    const fetchPoints = async () => {
        const ticker = tickerSelect.value;
        const interval = intervalSelect.value;
        const url = new URL(`${apiBase}/${ticker}`);
        url.searchParams.set('interval', interval);

        const res = await fetch(url);
        const json = await res.json();
        if (!res.ok) {
            throw new Error(json.message || 'Ошибка загрузки данных');
        }
        return json.points;
    };

    // Получаем последнюю цену (независимо от выбранного интервала)
    const fetchCurrentPrice = async () => {
        const ticker = tickerSelect.value;
        const url = new URL(`${apiBase}/${ticker}`);
        url.searchParams.set('interval', 1); // минимальный доступный интервал
        try {
            const res = await fetch(url);
            const json = await res.json();
            if (!res.ok) throw new Error(json.message || 'Ошибка загрузки цены');
            const pts = json.points || [];
            if (pts.length === 0) return null;
            const last = pts[pts.length - 1];
            return Number(last.close);
        } catch (e) {
            console.error('Ошибка обновления цены', e);
            return null;
        }
    };

    // Обновляет текст текущей цены из минутного интервала, не меняя график
    const updatePriceText = async () => {
        if (!currentPriceEl) return;
        const price = await fetchCurrentPrice();
        if (Number.isFinite(price)) {
            currentPriceEl.textContent = `Текущая цена: ${price.toFixed(2)}`;
        }
    };

    const updateDetailsLink = () => {
        if (!detailsLink || !detailsBase) return;
        const currentTicker = tickerSelect.value;
        if (currentTicker) {
            detailsLink.href = `${detailsBase}/${currentTicker}`;
            if (excelLink) {
                excelLink.href = `/securities/${currentTicker}/export/excel`;
                excelLink.style.display = 'inline-flex';
            }
            if (pdfLink) {
                pdfLink.href = `/securities/${currentTicker}/export/pdf`;
                pdfLink.style.display = 'inline-flex';
            }
        }
    };

    const fetchCsvData = async () => {
        const ticker = tickerSelect.value;
        const url = `${apiBase}/${ticker}/csv`;
        const res = await fetch(url);
        const json = await res.json();
        if (!res.ok) {
            throw new Error(json.message || 'Ошибка загрузки CSV данных');
        }
        console.log(`CSV данные загружены: ${json.count} точек`);
        if (json.points.length > 0) {
            console.log(`Первая точка: ${json.points[0].time}, Последняя: ${json.points[json.points.length - 1].time}`);
        }
        return json.points;
    };

    const toDate = (t) => {
        // MOEX API возвращает время в московском времени (UTC+3)
        // Преобразуем "YYYY-MM-DD HH:mm:ss" к Date, указывая московский часовой пояс
        const timeStr = String(t).replace(' ', 'T');
        // Добавляем смещение для московского времени (UTC+3)
        return new Date(timeStr + '+03:00');
    };

    // Определяет начальный диапазон в часах в зависимости от интервала
    const getInitialViewHours = (interval) => {
        switch (interval) {
            case 1: // 1 минута
                return 1; // последний час
            case 10: // 10 минут
                return 6; // последние 6 часов
            case 60: // 1 час
                return 24; // последние 24 часа
            case 24: // 1 день
                return 24 * 7; // последние 7 дней
            case 7: // 1 неделя
                return 24 * 28; // последние 4 недели (~28 дней)
            case 31: // 1 месяц
                return 24 * 90; // последние 3 месяца (~90 дней)
            default:
                return 24; // по умолчанию 24 часа
        }
    };

    // Определяет минимальный диапазон зума в миллисекундах на основе реальных данных
    // Минимальный зум = время, которое занимают 10 точек данных
    const getMinZoomRange = (points) => {
        if (!points || points.length < 2) {
            // Если данных недостаточно, используем значение по умолчанию
            const currentInterval = Number(intervalSelect.value);
            return currentInterval * 60 * 1000 * 10; // 10 интервалов
        }

        // Вычисляем средний интервал между точками
        let totalInterval = 0;
        let count = 0;

        for (let i = 1; i < Math.min(points.length, 100); i++) { // Проверяем первые 100 точек
            const time1 = toDate(points[i - 1].time).getTime();
            const time2 = toDate(points[i].time).getTime();
            const interval = time2 - time1;
            if (interval > 0) {
                totalInterval += interval;
                count++;
            }
        }

        if (count === 0) {
            const currentInterval = Number(intervalSelect.value);
            return currentInterval * 60 * 1000 * 10;
        }

        const avgInterval = totalInterval / count;
        return avgInterval * 10; // Минимум 10 точек
    };

    // Продолжительность интервала в миллисекундах
    const getIntervalMs = (interval) => {
        switch (interval) {
            case 1: return 60 * 1000; // 1 минута
            case 10: return 10 * 60 * 1000; // 10 минут
            case 60: return 60 * 60 * 1000; // 1 час
            case 24: return 24 * 60 * 60 * 1000; // 1 день
            case 7: return 7 * 24 * 60 * 60 * 1000; // 1 неделя
            case 31: return 30 * 24 * 60 * 60 * 1000; // 1 месяц (≈30 дней)
            default: return 24 * 60 * 60 * 1000;
        }
    };

    // Период автообновления данных графика в зависимости от интервала
    const getRefreshMs = (interval) => {
        switch (interval) {
            case 1: return 60_000;          // каждую минуту
            case 10: return 120_000;        // раз в 2 минуты
            case 60: return 300_000;        // раз в 5 минут
            case 24: return 900_000;        // раз в 15 минут
            case 7: return 900_000;         // раз в 15 минут
            case 31: return 1_800_000;      // раз в 30 минут
            default: return 300_000;        // по умолчанию 5 минут
        }
    };

    // Функция для проверки и корректировки диапазона зума
    const checkAndCorrectZoomRange = (chart) => {
        if (!chart || !chart.scales || !chart.scales.x) {
            return;
        }

        const xScale = chart.scales.x;
        if (!xScale) return;

        const currentMin = typeof xScale.min === 'object' ? xScale.min.getTime() : xScale.min;
        const currentMax = typeof xScale.max === 'object' ? xScale.max.getTime() : xScale.max;

        if (currentMin === undefined || currentMax === undefined || isNaN(currentMin) || isNaN(currentMax)) {
            return;
        }

        const currentRange = currentMax - currentMin;

        // Получаем минимальный диапазон на основе реальных данных (минимум 10 точек)
        const minRange = getMinZoomRange(allPoints);

        // Если диапазон меньше минимального, корректируем
        if (currentRange < minRange) {
            const center = (currentMin + currentMax) / 2;
            let newMin = center - minRange / 2;
            let newMax = center + minRange / 2;

            // Проверяем границы оси: слева по первой свече, справа на 2 интервала правее последней
            const dataMin = minDate ? minDate.getTime() : newMin;
            const dataMax = axisMaxDate ? axisMaxDate.getTime() : newMax;

            if (newMin < dataMin) {
                newMin = dataMin;
                newMax = Math.min(dataMin + minRange, dataMax);
            }
            if (newMax > dataMax) {
                newMax = dataMax;
                newMin = Math.max(dataMax - minRange, dataMin);
            }

            // Устанавливаем новые границы напрямую
            chart.options.scales.x.min = newMin;
            chart.options.scales.x.max = newMax;
            xScale.min = newMin;
            xScale.max = newMax;
            chart.update('none');
        }
    };

    // Функция для обновления ширины свечей в зависимости от видимого диапазона
    const updateCandleWidth = (chart, visibleRange) => {
        if (!chart || !chart.data || !chart.data.datasets || !chart.data.datasets[0]) {
            return;
        }

        const dataset = chart.data.datasets[0];
        if (!allPoints || allPoints.length === 0) {
            return;
        }

        // Вычисляем средний интервал между точками
        let totalInterval = 0;
        let count = 0;
        for (let i = 1; i < Math.min(allPoints.length, 100); i++) {
            const time1 = toDate(allPoints[i - 1].time).getTime();
            const time2 = toDate(allPoints[i].time).getTime();
            const interval = time2 - time1;
            if (interval > 0) {
                totalInterval += interval;
                count++;
            }
        }

        if (count === 0) {
            return;
        }

        const avgInterval = totalInterval / count;
        // Вычисляем примерное количество видимых свечей
        const visibleCandles = Math.ceil(visibleRange / avgInterval);

        // Ширина свечи зависит от количества видимых свечей
        let barThickness = 6;
        let maxBarThickness = 8;

        if (visibleCandles > 50) {
            barThickness = 2;
            maxBarThickness = 3;
        } else if (visibleCandles > 30) {
            barThickness = 3;
            maxBarThickness = 4;
        } else if (visibleCandles > 20) {
            barThickness = 4;
            maxBarThickness = 5;
        } else if (visibleCandles > 10) {
            barThickness = 5;
            maxBarThickness = 6;
        } else {
            barThickness = 6;
            maxBarThickness = 8;
        }

        // Обновляем ширину свечей
        dataset.barThickness = barThickness;
        dataset.maxBarThickness = maxBarThickness;
        chart.update('none');
    };

    const setActiveButtons = () => {
        if (switchToLine) {
            switchToLine.classList.toggle('bg-blue-600', currentMode === 'line');
            switchToLine.classList.toggle('text-white', currentMode === 'line');
            switchToLine.classList.toggle('bg-blue-50', currentMode !== 'line');
            switchToLine.classList.toggle('text-blue-700', currentMode !== 'line');
        }
        if (switchToCandle) {
            switchToCandle.classList.toggle('bg-amber-600', currentMode === 'candle');
            switchToCandle.classList.toggle('text-white', currentMode === 'candle');
            switchToCandle.classList.toggle('bg-amber-50', currentMode !== 'candle');
            switchToCandle.classList.toggle('text-amber-700', currentMode !== 'candle');
        }
    };

    const renderChart = (mode, points) => {
        const labels = points.map((p) => toDate(p.time));

        if (chartInstance) chartInstance.destroy();

        // Определяем начальный масштаб в зависимости от интервала
        let initialMin = undefined;
        let initialMax = undefined;
        if (points.length > 0 && maxDate) {
            const currentInterval = Number(intervalSelect.value);
            const intervalMs = getIntervalMs(currentInterval);
            const lastPointTime = toDate(points[points.length - 1].time).getTime();
            let hoursToShow = 24; // По умолчанию 24 часа

            // Определяем диапазон в зависимости от интервала
            switch (currentInterval) {
                case 1: // 1 минута
                    hoursToShow = 3; // последние 3 часа
                    break;
                case 10: // 10 минут
                    hoursToShow = 6; // последние 6 часов
                    break;
                case 60: // 1 час
                    hoursToShow = 24 * 3; // последние 3 дня
                    break;
                case 24: // 1 день
                    hoursToShow = 24 * 7 * 3; // последние 3 недели
                    break;
                case 7: // 1 неделя
                    hoursToShow = 24 * 30 * 3; // последние 3 месяца
                    break;
                case 31: // 1 месяц
                    hoursToShow = 24 * 365 * 3; // последние 3 года
                    break;
                default:
                    hoursToShow = 24; // по умолчанию 24 часа
            }

            // Расширяем диапазон вправо на два интервала
            const extendedMax = lastPointTime + intervalMs * 2;
            const hoursAgo = new Date(extendedMax - hoursToShow * 60 * 60 * 1000);
            initialMin = hoursAgo.getTime();
            initialMax = extendedMax;

            // Сохраняем максимальную дату оси для использования в лимитах зума/панорамирования
            axisMaxDate = new Date(extendedMax);
        }

        // Показываем текущую цену на минутном интервале (независимо от выбранного)
        updatePriceText();
        updateDetailsLink();

        // Определяем минимальный диапазон зума на основе реальных данных (минимум 10 точек)
        const minZoomRange = getMinZoomRange(points);

        // Определяем формат отображения в зависимости от интервала
        const currentInterval = Number(intervalSelect.value);
        const isMonthInterval = currentInterval === 31;
        const isDayInterval = currentInterval === 24;
        const isWeekInterval = currentInterval === 7;

        // Формат для разных интервалов
        let timeDisplayFormats;
        if (isMonthInterval) {
            // Интервал месяц - день.месяц.год
            timeDisplayFormats = {
                millisecond: 'dd.MM.yyyy',
                second: 'dd.MM.yyyy',
                minute: 'dd.MM.yyyy',
                hour: 'dd.MM.yyyy',
                day: 'dd.MM.yyyy',
                week: 'dd.MM.yyyy',
                month: 'dd.MM.yyyy',
                quarter: 'dd.MM.yyyy',
                year: 'yyyy',
            };
        } else if (isDayInterval || isWeekInterval) {
            // Интервалы день и неделя - день.месяц (без года и времени)
            timeDisplayFormats = {
                millisecond: 'dd.MM',
                second: 'dd.MM',
                minute: 'dd.MM',
                hour: 'dd.MM',
                day: 'dd.MM',
                week: 'dd.MM',
                month: 'dd.MM',
                quarter: 'dd.MM',
                year: 'yyyy',
            };
        } else {
            // Остальные интервалы - день.месяц час:минута
            timeDisplayFormats = {
                millisecond: 'dd.MM HH:mm:ss.SSS',
                second: 'dd.MM HH:mm:ss',
                minute: 'dd.MM HH:mm',
                hour: 'dd.MM HH:mm',
                day: 'dd.MM HH:mm',
                week: 'dd.MM HH:mm',
                month: 'dd.MM HH:mm',
                quarter: 'dd.MM HH:mm',
                year: 'dd.MM HH:mm',
            };
        }

        if (mode === 'line') {
            chartInstance = new Chart(chartCanvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'Close',
                            data: points.map((p) => p.close),
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.1)',
                            tension: 0.1,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            displayColors: false, // Убираем цветной квадрат
                            callbacks: {
                                label: function (context) {
                                    // Для линейного графика показываем OHLC данные
                                    const dataIndex = context.dataIndex;
                                    if (dataIndex >= 0 && dataIndex < allPoints.length) {
                                        const point = allPoints[dataIndex];
                                        return [
                                            'O: ' + point.open.toFixed(2),
                                            'H: ' + point.high.toFixed(2),
                                            'L: ' + point.low.toFixed(2),
                                            'C: ' + point.close.toFixed(2)
                                        ];
                                    }
                                    return '';
                                }
                            }
                        },
                        zoom: {
                            zoom: {
                                wheel: {
                                    enabled: true,
                                    speed: 0.02, // Медленный и плавный зум
                                },
                                pinch: {
                                    enabled: true,
                                    speed: 0.02, // Медленный и плавный зум для touch
                                },
                                mode: 'x',
                                limits: {
                                    x: {
                                        min: minDate ? minDate.getTime() : undefined,
                                        max: axisMaxDate ? axisMaxDate.getTime() : undefined,
                                    },
                                },
                                onZoomComplete: ({ chart }) => {
                                    // Проверяем минимальный диапазон после зума
                                    const xScale = chart.scales.x;
                                    if (!xScale) return;

                                    const currentMin = typeof xScale.min === 'object' ? xScale.min.getTime() : xScale.min;
                                    const currentMax = typeof xScale.max === 'object' ? xScale.max.getTime() : xScale.max;
                                    const currentRange = currentMax - currentMin;

                                    // Если диапазон меньше минимального, корректируем
                                    if (currentRange < minZoomRange) {
                                        const center = (currentMin + currentMax) / 2;
                                        let newMin = center - minZoomRange / 2;
                                        let newMax = center + minZoomRange / 2;

                                        // Проверяем границы данных
                                        const dataMin = minDate ? minDate.getTime() : newMin;
                                        const dataMax = maxDate ? maxDate.getTime() : newMax;

                                        if (newMin < dataMin) {
                                            newMin = dataMin;
                                            newMax = dataMin + minZoomRange;
                                        }
                                        if (newMax > dataMax) {
                                            newMax = dataMax;
                                            newMin = dataMax - minZoomRange;
                                        }

                                        // Устанавливаем новые границы
                                        chart.options.scales.x.min = newMin;
                                        chart.options.scales.x.max = newMax;
                                        chart.update('none');
                                    }
                                },
                            },
                            pan: {
                                enabled: true,
                                mode: 'x',
                                speed: 10, // Плавность панорамирования
                                threshold: 10, // Порог для начала панорамирования
                                limits: {
                                    x: {
                                        min: minDate ? minDate.getTime() : undefined,
                                        max: axisMaxDate ? axisMaxDate.getTime() : undefined,
                                    },
                                },
                            },
                            limits: {
                                x: {
                                    min: minDate ? minDate.getTime() : undefined,
                                    max: axisMaxDate ? axisMaxDate.getTime() : undefined,
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                tooltipFormat: 'yyyy-MM-dd HH:mm',
                                displayFormats: timeDisplayFormats,
                            },
                            min: initialMin,
                            max: initialMax,
                            ticks: {
                                source: 'auto',
                                maxRotation: 45,
                                minRotation: 0,
                                autoSkip: true,
                            },
                        },
                        y: { beginAtZero: false },
                    },
                },
            });
        } else {
            const candleData = points.map((p) => ({
                x: toDate(p.time),
                o: p.open,
                h: p.high,
                l: p.low,
                c: p.close,
            }));

            // Вычисляем динамическую ширину свечей в зависимости от количества видимых точек
            const visibleRange = initialMax && initialMin ? initialMax - initialMin : undefined;
            let barThickness = 6;
            let maxBarThickness = 8;

            if (visibleRange && points.length > 0) {
                // Вычисляем средний интервал между точками
                let totalInterval = 0;
                let count = 0;
                for (let i = 1; i < Math.min(points.length, 100); i++) {
                    const time1 = toDate(points[i - 1].time).getTime();
                    const time2 = toDate(points[i].time).getTime();
                    const interval = time2 - time1;
                    if (interval > 0) {
                        totalInterval += interval;
                        count++;
                    }
                }

                if (count > 0) {
                    const avgInterval = totalInterval / count;
                    // Вычисляем примерное количество видимых свечей
                    const visibleCandles = Math.ceil(visibleRange / avgInterval);
                    // Ширина свечи зависит от количества видимых свечей
                    // Чем больше свечей видно, тем уже они должны быть
                    if (visibleCandles > 50) {
                        barThickness = 2;
                        maxBarThickness = 3;
                    } else if (visibleCandles > 30) {
                        barThickness = 3;
                        maxBarThickness = 4;
                    } else if (visibleCandles > 20) {
                        barThickness = 4;
                        maxBarThickness = 5;
                    } else if (visibleCandles > 10) {
                        barThickness = 5;
                        maxBarThickness = 6;
                    } else {
                        barThickness = 6;
                        maxBarThickness = 8;
                    }
                }
            }

            chartInstance = new Chart(chartCanvas, {
                type: 'candlestick',
                data: {
                    labels: labels, // Добавляем labels для отображения меток на оси X
                    datasets: [
                        {
                            label: 'OHLC',
                            data: candleData,
                            borderColor: '#111827',
                            barThickness: barThickness,
                            maxBarThickness: maxBarThickness,
                            color: {
                                up: '#16a34a',
                                down: '#dc2626',
                                unchanged: '#6b7280',
                            },
                        },
                    ],
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            displayColors: false, // Убираем цветной квадрат
                            callbacks: {
                                label: function (context) {
                                    // Для свечного графика показываем OHLC данные
                                    const point = context.raw;
                                    if (point && typeof point === 'object' && 'o' in point) {
                                        return [
                                            'O: ' + point.o.toFixed(2),
                                            'H: ' + point.h.toFixed(2),
                                            'L: ' + point.l.toFixed(2),
                                            'C: ' + point.c.toFixed(2)
                                        ];
                                    }
                                    return '';
                                }
                            }
                        },
                        zoom: {
                            zoom: {
                                wheel: {
                                    enabled: true,
                                    speed: 0.02, // Медленный и плавный зум
                                },
                                pinch: {
                                    enabled: true,
                                    speed: 0.02, // Медленный и плавный зум для touch
                                },
                                mode: 'x',
                                limits: {
                                    x: {
                                        min: minDate ? minDate.getTime() : undefined,
                                        max: axisMaxDate ? axisMaxDate.getTime() : undefined,
                                    },
                                },
                                onZoomComplete: ({ chart }) => {
                                    // Проверяем минимальный диапазон после зума
                                    const xScale = chart.scales.x;
                                    if (!xScale) return;

                                    const currentMin = typeof xScale.min === 'object' ? xScale.min.getTime() : xScale.min;
                                    const currentMax = typeof xScale.max === 'object' ? xScale.max.getTime() : xScale.max;
                                    const currentRange = currentMax - currentMin;

                                    // Если диапазон меньше минимального, корректируем
                                    if (currentRange < minZoomRange) {
                                        const center = (currentMin + currentMax) / 2;
                                        let newMin = center - minZoomRange / 2;
                                        let newMax = center + minZoomRange / 2;

                                        // Проверяем границы данных
                                        const dataMin = minDate ? minDate.getTime() : newMin;
                                        const dataMax = maxDate ? maxDate.getTime() : newMax;

                                        if (newMin < dataMin) {
                                            newMin = dataMin;
                                            newMax = dataMin + minZoomRange;
                                        }
                                        if (newMax > dataMax) {
                                            newMax = dataMax;
                                            newMin = dataMax - minZoomRange;
                                        }

                                        // Устанавливаем новые границы
                                        chart.options.scales.x.min = newMin;
                                        chart.options.scales.x.max = newMax;
                                        chart.update('none');
                                    }

                                    // Обновляем ширину свечей при зуме/отдалении (только для свечного графика)
                                    if (mode === 'candle') {
                                        updateCandleWidth(chart, currentRange);
                                    }
                                },
                            },
                            pan: {
                                enabled: true,
                                mode: 'x',
                                speed: 10, // Плавность панорамирования
                                threshold: 10, // Порог для начала панорамирования
                                limits: {
                                    x: {
                                        min: minDate ? minDate.getTime() : undefined,
                                        max: maxDate ? maxDate.getTime() : undefined,
                                    },
                                },
                            },
                            limits: {
                                x: {
                                    min: minDate ? minDate.getTime() : undefined,
                                    max: axisMaxDate ? axisMaxDate.getTime() : undefined,
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                tooltipFormat: 'yyyy-MM-dd HH:mm',
                                displayFormats: timeDisplayFormats,
                            },
                            min: initialMin,
                            max: initialMax,
                            ticks: {
                                source: 'auto',
                                maxRotation: 45,
                                minRotation: 0,
                                autoSkip: true,
                            },
                        },
                        y: { beginAtZero: false },
                    },
                },
            });
        }

        setActiveButtons();

        // Добавляем обработчик панорамирования
        if (chartInstance) {
            chartInstance.canvas.addEventListener('pan', handlePan);
            chartInstance.canvas.addEventListener('panend', handlePan);

            // Перехватываем все изменения масштаба через afterUpdate hook
            const originalAfterUpdate = chartInstance.options.plugins?.zoom?.zoom?.onZoomComplete;

            // Добавляем проверку после каждого обновления
            const checkZoomAfterUpdate = () => {
                requestAnimationFrame(() => {
                    checkAndCorrectZoomRange(chartInstance);
                });
            };

            // Перехватываем wheel события для немедленной проверки
            let lastWheelTime = 0;
            const handleWheel = (e) => {
                const now = Date.now();
                if (now - lastWheelTime > 10) { // Более частая проверка
                    lastWheelTime = now;
                    requestAnimationFrame(() => {
                        checkAndCorrectZoomRange(chartInstance);
                        // Обновляем ширину свечей при зуме (только для свечного графика)
                        if (currentMode === 'candle' && chartInstance && chartInstance.scales && chartInstance.scales.x) {
                            const xScale = chartInstance.scales.x;
                            const currentMin = typeof xScale.min === 'object' ? xScale.min.getTime() : xScale.min;
                            const currentMax = typeof xScale.max === 'object' ? xScale.max.getTime() : xScale.max;
                            if (currentMin !== undefined && currentMax !== undefined) {
                                const currentRange = currentMax - currentMin;
                                updateCandleWidth(chartInstance, currentRange);
                            }
                        }
                    });
                }
            };

            chartInstance.canvas.addEventListener('wheel', handleWheel, { passive: true });
            chartInstance.canvas.addEventListener('touchmove', handleWheel, { passive: true });

            // Перехватываем изменения масштаба через переопределение свойств scale
            const xScale = chartInstance.scales.x;
            if (xScale) {
                let lastMin = xScale.min;
                let lastMax = xScale.max;

                // Проверяем изменения масштаба через setInterval
                const scaleCheckInterval = setInterval(() => {
                    if (!chartInstance || !chartInstance.scales || !chartInstance.scales.x) {
                        clearInterval(scaleCheckInterval);
                        return;
                    }

                    const currentMin = chartInstance.scales.x.min;
                    const currentMax = chartInstance.scales.x.max;

                    if (currentMin !== lastMin || currentMax !== lastMax) {
                        lastMin = currentMin;
                        lastMax = currentMax;
                        checkAndCorrectZoomRange(chartInstance);
                    }
                }, 50); // Проверяем каждые 50мс
            }

            // Также проверяем при каждом обновлении графика
            const originalUpdate = chartInstance.update.bind(chartInstance);
            chartInstance.update = function (mode) {
                const result = originalUpdate(mode);
                requestAnimationFrame(() => {
                    checkAndCorrectZoomRange(chartInstance);
                });
                return result;
            };
        }
    };

    // Загружает данные и обновляет служебные даты
    const loadData = async () => {
        allPoints = await fetchPoints();

        allPoints.sort((a, b) => {
            const dateA = toDate(a.time).getTime();
            const dateB = toDate(b.time).getTime();
            return dateA - dateB;
        });

        if (allPoints.length > 0) {
            minDate = toDate(allPoints[0].time);
            maxDate = toDate(allPoints[allPoints.length - 1].time);

            // Сдвигаем ось вправо на два интервала от последней точки
            const currentInterval = Number(intervalSelect.value);
            const intervalMs = getIntervalMs(currentInterval);
            axisMaxDate = new Date(maxDate.getTime() + intervalMs * 2);
        } else {
            minDate = null;
            maxDate = null;
            axisMaxDate = null;
        }
    };

    const loadAndRender = async () => {
        try {
            await loadData();
            if (allPoints.length === 0) return;
            renderChart(currentMode, allPoints);
        } catch (e) {
            console.error(e);
        }
    };

    // Обновление графика без потери текущего зума/позиции
    const refreshChartPreserveView = async () => {
        // Сохраняем текущий диапазон оси X
        const xScale = chartInstance?.scales?.x;
        const prevMin = xScale
            ? (typeof xScale.min === 'object' ? xScale.min.getTime() : xScale.min)
            : undefined;
        const prevMax = xScale
            ? (typeof xScale.max === 'object' ? xScale.max.getTime() : xScale.max)
            : undefined;

        await loadData();

        if (!chartInstance || allPoints.length === 0) {
            renderChart(currentMode, allPoints);
            return;
        }

        const labels = allPoints.map((p) => toDate(p.time));
        const currentInterval = Number(intervalSelect.value);
        const intervalMs = getIntervalMs(currentInterval);

        if (currentMode === 'line') {
            chartInstance.data.labels = labels;
            chartInstance.data.datasets[0].data = allPoints.map((p) => p.close);
        } else {
            chartInstance.data.labels = labels;
            chartInstance.data.datasets[0].data = allPoints.map((p) => ({
                x: toDate(p.time),
                o: p.open,
                h: p.high,
                l: p.low,
                c: p.close,
            }));
        }

        // Обновляем лимиты, включая запас вправо на два интервала
        const dataMin = minDate ? minDate.getTime() : undefined;
        const dataMax = axisMaxDate ? axisMaxDate.getTime() : undefined;

        // Восстанавливаем прежний диапазон, но ограничиваем его данными
        let newMin = prevMin ?? dataMin;
        let newMax = prevMax ?? dataMax;

        if (dataMin !== undefined && newMin < dataMin) newMin = dataMin;
        if (dataMax !== undefined && newMax > dataMax) newMax = dataMax;
        if (newMin !== undefined && newMax !== undefined && newMin >= newMax) {
            // Если диапазон схлопнулся, расширяем от конца данных
            newMin = dataMax !== undefined ? dataMax - intervalMs * 20 : undefined;
            newMax = dataMax;
        }

        // Применяем новые границы и лимиты зума/панорамирования
        chartInstance.options.scales.x.min = newMin;
        chartInstance.options.scales.x.max = newMax;
        chartInstance.options.plugins.zoom.zoom.limits.x.max = dataMax;
        chartInstance.options.plugins.zoom.pan.limits.x.max = dataMax;
        chartInstance.options.plugins.zoom.zoom.limits.x.min = dataMin;
        chartInstance.options.plugins.zoom.pan.limits.x.min = dataMin;

        chartInstance.update('none');
        checkAndCorrectZoomRange(chartInstance);
    };

    // Автообновление текущей цены раз в минуту, не трогая график/зум/скролл
    const startPriceAutoUpdate = () => {
        const update = async () => {
            await updatePriceText();
        };
        // Старт сразу
        update();
        if (priceIntervalId) clearInterval(priceIntervalId);
        // Обновляем только цену, график не трогаем -> зум и положение сохраняются
        priceIntervalId = setInterval(update, 60_000);
    };

    // Автообновление данных графика с периодом по интервалу, без сброса зума
    const startChartAutoUpdate = (immediate = false) => {
        const refreshMs = getRefreshMs(Number(intervalSelect.value));
        if (chartIntervalId) clearInterval(chartIntervalId);

        const tick = () => refreshChartPreserveView();
        if (immediate) tick();
        chartIntervalId = setInterval(tick, refreshMs);
    };

    // Обработчик события панорамирования для подгрузки данных
    const handlePan = () => {
        if (!chartInstance || !minDate || allPoints.length === 0) {
            return;
        }

        const scales = chartInstance.scales;
        if (!scales || !scales.x) {
            return;
        }

        const xScale = scales.x;
        const min = xScale.min;
        const max = xScale.max;

        // Если скроллим влево (к старым данным) и достигли начала
        const minDateMs = minDate.getTime();
        const currentMinMs = typeof min === 'object' ? min.getTime() : min;

        // Если приближаемся к началу данных (в пределах 10% от диапазона)
        const range = max - min;
        const threshold = range * 0.1;

        if (currentMinMs - threshold <= minDateMs) {
            // Уже показываем все данные из CSV, ничего не загружаем
            return;
        }
    };

    // Обработчики событий при изменении параметров
    tickerSelect.addEventListener('change', () => {
        loadAndRender();
        updateDetailsLink();
        startChartAutoUpdate();
        startPriceAutoUpdate();
    });

    intervalSelect.addEventListener('change', () => {
        // Обновляем состояние кнопок при программном изменении
        if (intervalButtonsContainer) {
            intervalButtonsContainer.querySelectorAll('button').forEach(btn => {
                if (Number(btn.value) === Number(intervalSelect.value)) {
                    btn.classList.remove('bg-white', 'text-gray-700', 'border-gray-300', 'hover:bg-gray-50');
                    btn.classList.add('bg-blue-600', 'text-white', 'border-blue-600');
                } else {
                    btn.classList.remove('bg-blue-600', 'text-white', 'border-blue-600');
                    btn.classList.add('bg-white', 'text-gray-700', 'border-gray-300', 'hover:bg-gray-50');
                }
            });
        }
        loadAndRender();
        startChartAutoUpdate(true);
    });

    switchToLine?.addEventListener('click', (e) => {
        e.preventDefault();
        currentMode = 'line';
        loadAndRender();
    });

    switchToCandle?.addEventListener('click', (e) => {
        e.preventDefault();
        currentMode = 'candle';
        loadAndRender();
    });

    // Первый запуск
    setActiveButtons();
    loadAndRender();
    updateDetailsLink();
    startChartAutoUpdate();
    startPriceAutoUpdate();
});
