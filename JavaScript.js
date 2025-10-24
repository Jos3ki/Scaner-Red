document.addEventListener('DOMContentLoaded', () => {
            const scanSearch = document.getElementById('scanSearch');
            const reporteContainer = document.getElementById('reporteContainer');
            const showAllBtn = document.getElementById('showAllBtn');
            const statusGuide = document.getElementById('status-guide-container');
            const historyModal = document.getElementById('historyModal');
            const closeModalBtn = document.getElementById('closeModalBtn');
            const historyDetails = document.getElementById('historyDetails');
            const loadingHistory = document.getElementById('loadingHistory');
            let allData = [];

            
            const fetchHistory = (mac) => {
                historyModal.classList.remove('hidden');
                historyDetails.innerHTML = '';
                loadingHistory.classList.remove('hidden');
                document.getElementById('modalMac').textContent = mac;

                fetch(`api.php?history_mac=${encodeURIComponent(mac)}`)
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => { throw new Error(err.error || 'Fallo en la petición de red'); });
                        }
                        return response.json();
                    })
                    .then(data => {
                        loadingHistory.classList.add('hidden');
                        historyDetails.innerHTML = ''; 

                        if (data.error || data.length === 0) {
                            historyDetails.innerHTML = `<p style="color: red; text-align: center;">${data.error || 'No se encontró historial o la MAC no está registrada.'}</p>`;
                            return;
                        }
                        
                        
                        const totalEvents = data.length;
                        
                        
                        const displayData = JSON.parse(JSON.stringify(data));

                       
                        for (let index = 0; index < totalEvents; index++) {
                            const currentItem = displayData[index];
                            const nextItem = displayData[index + 1]; 
                            
                            currentItem.changeLabel = "";

                            if (index === 0) {
                                currentItem.changeLabel = " (ESTADO ACTUAL)";
                            } 
                            
                            
                            if (nextItem) {
                                const currentPorts = currentItem.puertos_abiertos_raw;
                                const nextPorts = nextItem.puertos_abiertos_raw;
                                
                                
                                if (currentItem.ip_address !== nextItem.ip_address) {
                                    
                                    nextItem.changeLabel = ` — CAMBIÓ IP A ${currentItem.ip_address}`; 
                                    
                                }
                                
                                
                                if (currentItem.ip_address === nextItem.ip_address && currentPorts !== nextPorts) {
                                    nextItem.changeLabel = ` — CAMBIO DE SERVICIOS`;
                                }
                            }
                        }
                        
                        
                        displayData.forEach((item, index) => {
                            const fecha = new Date(item.fecha_escaneo);
                            const fechaFormateada = fecha.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                            
                            const instanceNumber = totalEvents - index; 
                            
                            const historyCard = document.createElement('div');
                            historyCard.className = 'history-card';
                            historyCard.innerHTML = `
                                <h4>Instancia #${instanceNumber} ${item.changeLabel}</h4>
                                <p><strong>IP:</strong> ${item.ip_address}</p>
                                <p><strong>Host:</strong> ${item.hostname}</p>
                                <p><strong>Puertos:</strong> ${item.puertos_servicios_unidos || '-'}</p>
                                <p class="history-date"><strong>Fecha:</strong> ${fechaFormateada}</p>
                            `;
                            historyDetails.appendChild(historyCard);
                        });
                    })
                    .catch(error => {
                        loadingHistory.classList.add('hidden');
                        historyDetails.innerHTML = `<p style="color: red; text-align: center;">Error al cargar el historial.</p>`;
                        console.error('Error al obtener el historial:', error);
                    });
            };
            
            

            closeModalBtn.addEventListener('click', () => historyModal.classList.add('hidden'));

            
            const renderData = (data) => {
                reporteContainer.innerHTML = '';
                if (data.length === 0) {
                    reporteContainer.innerHTML = "<p>No se encontraron resultados para esta búsqueda.</p>";
                    statusGuide.classList.add('hidden');
                    return;
                }
                
                statusGuide.classList.remove('hidden');
                data.forEach(item => {
                    const fecha = new Date(item.fecha_escaneo);
                    const fechaFormateada = fecha.toLocaleString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                    
                    const hasPorts = item.puertos_servicios_unidos && item.puertos_servicios_unidos !== '';
                    const statusClass = hasPorts ? 'active' : 'inactive';

                    const card = document.createElement('div');
                    card.className = 'network-card';
                    
                    const puertosServiciosArray = item.puertos_servicios_unidos ? item.puertos_servicios_unidos.split(' ') : [];
                    
                    let puertosServiciosHTML = '';
                    if (puertosServiciosArray.length > 0 && puertosServiciosArray[0] !== '') {
                        puertosServiciosHTML = `<span class="port-service-wrapper">` + 
                            puertosServiciosArray.map(tag => `<span class="port-service-tag">${tag}</span>`).join('') + 
                            `</span>`;
                    } else {
                        puertosServiciosHTML = '<span>-</span>';
                    }

                   
                    const totalRegistros = item.total_registros_mac;
                    const canShowHistory = item.mac_address !== '00:00:00:00:00:00' && totalRegistros > 1; 
                    
                    let historyButtonHTML = canShowHistory 
                        ? `<button class="history-btn" data-mac="${item.mac_address}">Ver Historial (${totalRegistros})</button>` 
                        : '<span>Sin cambios</span>';
                    
                    let alertIconHTML = canShowHistory 
                        ? `<span class="material-icons change-alert-icon" title="Cambio o Movimiento detectado">autorenew</span>`
                        : '';

                    card.innerHTML = `
                        <div class="card-header">
                            <h3>${item.ip_address}</h3>
                            <div class="header-status-wrapper">
                                ${alertIconHTML}
                                <span class="status-dot ${statusClass}" title="${statusClass === 'active' ? 'Puertos Abiertos' : 'Sin Puertos'}"></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <p><strong>Nombre:</strong> <span>${item.hostname || 'N/A'}</span></p>
                            <p><strong>MAC:</strong> <span>${item.mac_address || '----'}</span></p>
                            <p><strong>Fabricante:</strong> <span>${item.fabricante_nombre || 'No registrado'}</span></p>
                            <p><strong>Puertos:</strong> ${puertosServiciosHTML}</p>
                            <p><strong>Último Escaneo:</strong> <span>${item.fecha_escaneo ? fechaFormateada : 'N/A'}</span></p>
                        </div>
                        <div class="card-footer">
                            ${historyButtonHTML}
                            <div class="card-footer-info">
                                <span>ID Equipo: ${item.id_equipo}</span>
                                <span class="material-icons">fingerprint</span>
                            </div>
                        </div>
                    `;
                    reporteContainer.appendChild(card);
                });

                
                reporteContainer.querySelectorAll('.history-btn').forEach(button => {
                    button.addEventListener('click', (e) => {
                        const mac = e.currentTarget.dataset.mac;
                        fetchHistory(mac);
                    });
                });
            };
        
            const fetchData = (query = '') => {
                fetch(`api.php?search=${encodeURIComponent(query)}`)
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => { throw new Error(err.error || 'Fallo en la petición de red'); });
                        }
                        return response.json();
                    })
                    .then(data => {
                        allData = data; 
                        renderData(allData);
                    })
                    .catch(error => {
                        reporteContainer.innerHTML = `<p style="color: red;">Error: ${error.message}</p>`;
                        console.error('Error al obtener los datos:', error);
                    });
            };

            
            scanSearch.addEventListener('input', (event) => {
                fetchData(event.target.value);
            });
        
            showAllBtn.addEventListener('click', () => {
                scanSearch.value = '';
                fetchData('');
            });
        });